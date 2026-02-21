<?php

/*
 * This file is part of ghostchu/openai-content-audit.
 *
 * Copyright (c) 2024 Ghost_chu.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ghostchu\Openaicontentaudit\Service;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use FoF\Upload\File;

class ContentExtractor
{
    private const MAX_CONTEXT_LENGTH = 5000;
    private const IMAGE_DOWNLOAD_TIMEOUT = 10;
    private const MAX_IMAGE_SIZE = 5 * 1024 * 1024; // 5MB

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Read local image file and convert to base64 data URI.
     *
     * @param string $disk Disk name (e.g., 'flarum-avatars', 'sycho-profile-cover')
     * @param string $path File path on the disk
     * @return string|null Base64 data URI or null if read fails
     */
    private function readLocalImage(string $disk, string $path): ?string
    {
        try {
            $filesystem = resolve(\Illuminate\Contracts\Filesystem\Factory::class);
            /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
            $storage = $filesystem->disk($disk);
            
            if (!$storage->exists($path)) {
                $this->logger->warning('[Content Extractor] Local image file not found', [
                    'disk' => $disk,
                    'path' => $path,
                ]);
                return null;
            }
            
            // Check file size
            $size = $storage->size($path);
            if ($size > self::MAX_IMAGE_SIZE) {
                $this->logger->warning('[Content Extractor] Local image too large', [
                    'disk' => $disk,
                    'path' => $path,
                    'size' => $size,
                ]);
                return null;
            }
            
            // Read file content
            $content = $storage->get($path);
            
            // Detect MIME type
            $mimeType = $storage->mimeType($path);
            if (!$mimeType || !str_starts_with($mimeType, 'image/')) {
                // Try to detect from file extension
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $mimeType = match($extension) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                    default => 'image/jpeg',
                };
            }
            
            $base64 = base64_encode($content);
            
            $this->logger->debug('[Content Extractor] Successfully read local image', [
                'disk' => $disk,
                'path' => $path,
                'size' => $size,
                'mime_type' => $mimeType,
            ]);
            
            return "data:{$mimeType};base64,{$base64}";
        } catch (\Exception $e) {
            $this->logger->error('[Content Extractor] Failed to read local image', [
                'disk' => $disk,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extract content from a post for auditing.
     *
     * @param Post $post
     * @return array
     */
    public function extractPost(Post $post): array
    {
        $content = [];
        $context = [];
        $images = [];

        // Extract post content
        if ($post instanceof CommentPost) {
            $rawContent = $post->content;
            $content['text'] = $this->stripHtml($rawContent);
            
            // Extract images from post content
            $imageUrls = $this->extractImagesFromContent($rawContent);
            foreach ($imageUrls as $imageUrl) {
                if ($this->shouldDownloadImages()) {
                    $imageData = $this->downloadImage($imageUrl);
                    if ($imageData) {
                        $images[] = [
                            'type' => 'post_image',
                            'data' => $imageData,
                        ];
                    } else {
                        $images[] = [
                            'type' => 'post_image',
                            'url' => $imageUrl,
                        ];
                    }
                } else {
                    $images[] = [
                        'type' => 'post_image',
                        'url' => $imageUrl,
                    ];
                }
            }
        }

        // Extract discussion context
        try {
            $discussion = $post->discussion;
            if ($discussion) {
                $context['discussion_title'] = $discussion->title;
                
                // Get first post content as context
                $firstPost = $discussion->firstPost;
                if ($firstPost && $firstPost->id !== $post->id && $firstPost instanceof CommentPost) {
                    $context['discussion_content'] = $this->truncate(
                        $this->stripHtml($firstPost->content),
                        self::MAX_CONTEXT_LENGTH
                    );
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('[Content Extractor] Failed to extract discussion context', [
                'post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Extract user context
        try {
            $user = $post->user;
            if ($user) {
                $context['username'] = $user->username;
                $context['display_name'] = $user->display_name;
            }
        } catch (\Exception $e) {
            $this->logger->warning('[Content Extractor] Failed to extract user context', [
                'post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'type' => 'post',
            'content' => $content,
            'context' => $context,
            'images' => $images,
        ];
    }

    /**
     * Extract content from a discussion for auditing.
     *
     * @param Discussion $discussion
     * @return array
     */
    public function extractDiscussion(Discussion $discussion): array
    {
        $content = [];
        $context = [];
        $images = [];

        // Extract discussion title
        $content['title'] = $discussion->title;

        // Extract first post content
        try {
            $firstPost = $discussion->firstPost;
            if ($firstPost && $firstPost instanceof CommentPost) {
                $rawContent = $firstPost->content;
                $content['content'] = $this->truncate(
                    $this->stripHtml($rawContent),
                    self::MAX_CONTEXT_LENGTH
                );
                
                // Extract images from discussion content
                $imageUrls = $this->extractImagesFromContent($rawContent);
                foreach ($imageUrls as $imageUrl) {
                    if ($this->shouldDownloadImages()) {
                        $imageData = $this->downloadImage($imageUrl);
                        if ($imageData) {
                            $images[] = [
                                'type' => 'discussion_image',
                                'data' => $imageData,
                            ];
                        } else {
                            $images[] = [
                                'type' => 'discussion_image',
                                'url' => $imageUrl,
                            ];
                        }
                    } else {
                        $images[] = [
                            'type' => 'discussion_image',
                            'url' => $imageUrl,
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('[Content Extractor] Failed to extract first post', [
                'discussion_id' => $discussion->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Extract user context
        try {
            $user = $discussion->user;
            if ($user) {
                $context['username'] = $user->username;
                $context['display_name'] = $user->display_name;
            }
        } catch (\Exception $e) {
            $this->logger->warning('[Content Extractor] Failed to extract user context', [
                'discussion_id' => $discussion->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'type' => 'discussion',
            'content' => $content,
            'context' => $context,
            'images' => $images,
        ];
    }

    /**
     * Extract user profile changes for auditing.
     *
     * @param User $user
     * @param array $changes Changed attributes
     * @return array
     */
    public function extractUserProfile(User $user, array $changes): array
    {
        $content = [];
        $context = [];
        $images = [];

        // Extract changed fields
        foreach ($changes as $field => $value) {
            switch ($field) {
                case 'username':
                    $content['username'] = $value;
                    break;
                case 'display_name':
                    $content['display_name'] = $value;
                    break;
                case 'bio':
                    $content['bio'] = $this->truncate($value, self::MAX_CONTEXT_LENGTH);
                    break;
                case 'avatar_url':
                    // Check if this is a local file
                    if (is_array($value) && isset($value['_local_file']) && $value['_local_file']) {
                        $content['avatar_url'] = $value['url'] ?? $value['_path'];
                        
                        $this->logger->debug('[Content Extractor] Processing local avatar file', [
                            'disk' => $value['_disk'],
                            'path' => $value['_path'],
                            'download_images' => $this->shouldDownloadImages(),
                        ]);
                        
                        // Read local file directly for vision analysis
                        if ($this->shouldDownloadImages()) {
                            $imageData = $this->readLocalImage($value['_disk'], $value['_path']);
                            if ($imageData) {
                                $this->logger->info('[Content Extractor] Successfully read local avatar file', [
                                    'path' => $value['_path'],
                                    'data_size' => strlen($imageData),
                                ]);
                                $images[] = [
                                    'type' => 'avatar',
                                    'data' => $imageData,
                                ];
                            } else {
                                $this->logger->warning('[Content Extractor] Failed to read local avatar, using URL fallback', [
                                    'path' => $value['_path'],
                                ]);
                                // Fallback to URL if local read fails
                                $images[] = [
                                    'type' => 'avatar',
                                    'url' => $value['url'] ?? $value['_path'],
                                ];
                            }
                        } else {
                            $this->logger->debug('[Content Extractor] Image download disabled, using URL', [
                                'url' => $value['url'] ?? $value['_path'],
                            ]);
                            $images[] = [
                                'type' => 'avatar',
                                'url' => $value['url'] ?? $value['_path'],
                            ];
                        }
                    } else {
                        // External URL, download as before
                        $content['avatar_url'] = $value;
                        if ($this->shouldDownloadImages()) {
                            $imageData = $this->downloadImage($value);
                            if ($imageData) {
                                $images[] = [
                                    'type' => 'avatar',
                                    'data' => $imageData,
                                ];
                            } else {
                                $images[] = [
                                    'type' => 'avatar',
                                    'url' => $value,
                                ];
                            }
                        } else {
                            $images[] = [
                                'type' => 'avatar',
                                'url' => $value,
                            ];
                        }
                    }
                    break;
                case 'cover':
                    // Check if this is a local file
                    if (is_array($value) && isset($value['_local_file']) && $value['_local_file']) {
                        $content['cover'] = $value['url'] ?? $value['_path'];
                        // Read local file directly for vision analysis
                        if ($this->shouldDownloadImages()) {
                            $imageData = $this->readLocalImage($value['_disk'], $value['_path']);
                            if ($imageData) {
                                $images[] = [
                                    'type' => 'cover',
                                    'data' => $imageData,
                                ];
                            } else {
                                // Fallback to URL if local read fails
                                $images[] = [
                                    'type' => 'cover',
                                    'url' => $value['url'] ?? $value['_path'],
                                ];
                            }
                        } else {
                            $images[] = [
                                'type' => 'cover',
                                'url' => $value['url'] ?? $value['_path'],
                            ];
                        }
                    } else {
                        // External URL, download as before
                        $content['cover'] = $value;
                        if ($this->shouldDownloadImages()) {
                            $imageData = $this->downloadImage($value);
                            if ($imageData) {
                                $images[] = [
                                    'type' => 'cover',
                                    'data' => $imageData,
                                ];
                            } else {
                                $images[] = [
                                    'type' => 'cover',
                                    'url' => $value,
                                ];
                            }
                        } else {
                            $images[] = [
                                'type' => 'cover',
                                'url' => $value,
                            ];
                        }
                    }
                    break;
            }
        }

        // Add context
        $context['user_id'] = $user->id;
        $context['joined_at'] = $user->joined_at?->toIso8601String();

        return [
            'type' => 'user_profile',
            'content' => $content,
            'context' => $context,
            'images' => $images,
        ];
    }

    /**
     * Extract file content for auditing (from fof/upload).
     *
     * @param File $file
     * @param array $metadata File metadata from event
     * @return array
     */
    public function extractFile(File $file, array $metadata): array
    {
        $content = [];
        $context = [];
        $images = [];

        $fileType = $metadata['file_type'] ?? 'unknown';
        $mime = $metadata['mime'] ?? 'unknown';
        
        // Add basic file info to context
        $context['file_id'] = $file->id;
        $context['file_name'] = $file->base_name;
        $context['file_size'] = $file->size;
        $context['mime_type'] = $mime;
        $context['upload_method'] = $file->upload_method ?? 'unknown';
        $context['uploaded_at'] = $file->created_at?->toIso8601String();

        if ($fileType === 'image') {
            // Handle image files
            $content['file_name'] = $file->base_name;
            
            $downloadEnabled = $this->shouldDownloadImages();
            
            $this->logger->debug('[Content Extractor] Processing uploaded image file', [
                'file_id' => $file->id,
                'file_name' => $file->base_name,
                'upload_method' => $file->upload_method,
                'path' => $file->path ?? 'null',
                'url' => $file->url ?? 'null',
                'download_enabled' => $downloadEnabled,
            ]);

            $imageData = null;
            $imageSource = null;
            
            // Try to get image data if downloading is enabled
            if ($downloadEnabled) {
                // Determine if we should read locally or download
                $isLocal = $file->upload_method === 'local';
                
                if ($isLocal && $file->path) {
                    // Try to read from local filesystem
                    // fof/upload stores files in 'files/' subdirectory under flarum-assets disk
                    $localPath = 'files/' . $file->path;
                    
                    $this->logger->debug('[Content Extractor] Attempting to read local image', [
                        'file_id' => $file->id,
                        'original_path' => $file->path,
                        'local_path' => $localPath,
                    ]);
                    
                    $imageData = $this->readLocalImage('flarum-assets', $localPath);
                    if ($imageData) {
                        $imageSource = 'local_file';
                        $this->logger->info('[Content Extractor] Successfully read local uploaded image', [
                            'file_id' => $file->id,
                            'path' => $localPath,
                        ]);
                    } else {
                        $this->logger->warning('[Content Extractor] Failed to read local image', [
                            'file_id' => $file->id,
                            'original_path' => $file->path,
                            'local_path' => $localPath,
                        ]);
                    }
                }
                
                // If local read failed or file is remote, try downloading from URL
                if (!$imageData && $file->url) {
                    $this->logger->debug('[Content Extractor] Attempting to download image from URL', [
                        'file_id' => $file->id,
                        'url' => $file->url,
                    ]);
                    
                    $imageData = $this->downloadImage($file->url);
                    if ($imageData) {
                        $imageSource = 'downloaded_url';
                        $this->logger->info('[Content Extractor] Successfully downloaded image from URL', [
                            'file_id' => $file->id,
                            'url' => $file->url,
                        ]);
                    } else {
                        $this->logger->warning('[Content Extractor] Failed to download image from URL', [
                            'file_id' => $file->id,
                            'url' => $file->url,
                        ]);
                    }
                }
            } else {
                $this->logger->debug('[Content Extractor] Image download disabled, will use URL reference only', [
                    'file_id' => $file->id,
                ]);
            }
            
            // Add image to results
            if ($imageData) {
                // Successfully got image data
                $images[] = [
                    'type' => 'uploaded_file',
                    'data' => $imageData,
                    'source' => $imageSource,
                ];
                $this->logger->info('[Content Extractor] Added image with data to audit', [
                    'file_id' => $file->id,
                    'source' => $imageSource,
                ]);
            } elseif ($file->url) {
                // Fallback to URL reference (OpenAI may not support this for all endpoints)
                $images[] = [
                    'type' => 'uploaded_file',
                    'url' => $file->url,
                ];
                $this->logger->warning('[Content Extractor] Added image URL reference only (no data)', [
                    'file_id' => $file->id,
                    'url' => $file->url,
                    'reason' => $downloadEnabled ? 'download_failed' : 'download_disabled',
                ]);
            } else {
                $this->logger->error('[Content Extractor] Cannot add image: no data and no URL', [
                    'file_id' => $file->id,
                ]);
            }
        } elseif ($fileType === 'text') {
            // Handle text files
            $content['file_name'] = $file->base_name;
            
            $this->logger->debug('[Content Extractor] Processing uploaded text file', [
                'file_id' => $file->id,
                'file_name' => $file->base_name,
                'upload_method' => $file->upload_method,
                'mime' => $mime,
            ]);

            // Read text content
            try {
                $filesystem = resolve(\Illuminate\Contracts\Filesystem\Factory::class);
                
                $isLocal = $file->upload_method === 'local';
                
                if ($isLocal && $file->path) {
                    // Read from local filesystem
                    // fof/upload stores files in 'files/' subdirectory under flarum-assets disk
                    $localPath = 'files/' . $file->path;
                    
                    /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
                    $storage = $filesystem->disk('flarum-assets');
                    
                    if ($storage->exists($localPath)) {
                        $textContent = $storage->get($localPath);
                        
                        // Truncate if too large
                        $maxSize = 64 * 1024; // 64KB
                        if (strlen($textContent) > $maxSize) {
                            $textContent = substr($textContent, 0, $maxSize) . "\n[... content truncated ...]";
                        }
                        
                        $content['file_content'] = $textContent;
                        
                        $this->logger->info('[Content Extractor] Successfully read local text file', [
                            'file_id' => $file->id,
                            'path' => $localPath,
                            'content_length' => strlen($textContent),
                        ]);
                    } else {
                        $this->logger->warning('[Content Extractor] Text file not found', [
                            'file_id' => $file->id,
                            'original_path' => $file->path,
                            'local_path' => $localPath,
                        ]);
                        $content['file_content'] = '[File not found]';
                    }
                } elseif ($file->url) {
                    // Download from remote URL
                    $this->logger->debug('[Content Extractor] Downloading remote text file', [
                        'file_id' => $file->id,
                        'url' => $file->url,
                    ]);
                    
                    $client = new Client([
                        'timeout' => self::IMAGE_DOWNLOAD_TIMEOUT,
                        'connect_timeout' => 5,
                    ]);

                    $response = $client->get($file->url);
                    $textContent = (string) $response->getBody();
                    
                    // Truncate if too large
                    $maxSize = 64 * 1024; // 64KB
                    if (strlen($textContent) > $maxSize) {
                        $textContent = substr($textContent, 0, $maxSize) . "\n[... content truncated ...]";
                    }
                    
                    $content['file_content'] = $textContent;
                    
                    $this->logger->info('[Content Extractor] Successfully downloaded remote text file', [
                        'file_id' => $file->id,
                        'url' => $file->url,
                        'content_length' => strlen($textContent),
                    ]);
                } else {
                    $this->logger->warning('[Content Extractor] Cannot read text file: no path or URL', [
                        'file_id' => $file->id,
                    ]);
                    $content['file_content'] = '[Cannot read file]';
                }
            } catch (\Exception $e) {
                $this->logger->error('[Content Extractor] Failed to read text file', [
                    'file_id' => $file->id,
                    'error' => $e->getMessage(),
                ]);
                $content['file_content'] = '[Error reading file: ' . $e->getMessage() . ']';
            }
        }

        return [
            'type' => 'upload',
            'content' => $content,
            'context' => $context,
            'images' => $images,
        ];
    }

    /**
     * Build messages array for OpenAI API.
     *
     * @param array $extractedData
     * @param string $systemPrompt
     * @return array
     */
    public function buildMessages(array $extractedData, string $systemPrompt): array
    {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        $userMessage = $this->formatUserMessage($extractedData);
        
        // Check if there are images with data (base64)
        $hasImageData = false;
        if (!empty($extractedData['images'])) {
            foreach ($extractedData['images'] as $image) {
                if (isset($image['data'])) {
                    $hasImageData = true;
                    break;
                }
            }
        }
        
        $this->logger->debug('[Content Extractor] Building messages', [
            'images_count' => count($extractedData['images'] ?? []),
            'has_image_data' => $hasImageData,
        ]);
        
        // Only build multimodal content if we have actual image data
        if ($hasImageData) {
            $content = [
                ['type' => 'text', 'text' => $userMessage],
            ];

            foreach ($extractedData['images'] as $image) {
                if (isset($image['data'])) {
                    // Base64 encoded image - use multimodal format
                    $content[] = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $image['data'],
                        ],
                    ];
                }
            }
            
            $this->logger->info('[Content Extractor] Using multimodal format', [
                'content_parts' => count($content),
            ]);

            $messages[] = ['role' => 'user', 'content' => $content];
        } else {
            $this->logger->info('[Content Extractor] Using text-only format');
            // Text-only message (including image URLs as text)
            $messages[] = ['role' => 'user', 'content' => $userMessage];
        }

        return $messages;
    }

    /**
     * Format extracted data as a user message.
     *
     * @param array $data
     * @return string
     */
    private function formatUserMessage(array $data): string
    {
        $parts = [];

        $parts[] = "Content Type: {$data['type']}";
        $parts[] = '';

        // Add content
        if (!empty($data['content'])) {
            $parts[] = 'Content to Review:';
            foreach ($data['content'] as $key => $value) {
                $parts[] = ucfirst(str_replace('_', ' ', $key)) . ': ' . $value;
            }
            $parts[] = '';
        }

        // Add image count if present
        if (!empty($data['images'])) {
            $imageCount = count($data['images']);
            $parts[] = "Images: {$imageCount} image(s) attached for review";
            $parts[] = '';
        }

        // Add context
        if (!empty($data['context'])) {
            $parts[] = 'Context:';
            foreach ($data['context'] as $key => $value) {
                $parts[] = ucfirst(str_replace('_', ' ', $key)) . ': ' . $value;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Strip HTML tags and decode entities.
     *
     * @param string $html
     * @return string
     */
    private function stripHtml(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Truncate text to a maximum length.
     *
     * @param string $text
     * @param int $maxLength
     * @return string
     */
    private function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength) . '...';
    }

    /**
     * Check if images should be downloaded.
     *
     * @return bool
     */
    private function shouldDownloadImages(): bool
    {
        return (bool) $this->settings->get('ghostchu.openaicontentaudit.download_images', true);
    }

    /**
     * Extract image URLs from content (XML, HTML, or Markdown).
     *
     * @param string $content
     * @return array Array of unique image URLs
     */
    private function extractImagesFromContent(string $content): array
    {
        $imageUrls = [];

        // 1. Extract from S9e TextFormatter IMG tags: <IMG src="url">
        if (preg_match_all('/<IMG\s+src=["\']([^"\']+)["\']/i', $content, $matches)) {
            $imageUrls = array_merge($imageUrls, $matches[1]);
        }

        // 2. Extract from HTML img tags: <img src="url">
        if (preg_match_all('/<img\s+[^>]*src=["\']([^"\']+)["\']/i', $content, $matches)) {
            $imageUrls = array_merge($imageUrls, $matches[1]);
        }

        // 3. Extract from Markdown syntax: ![alt](url)
        if (preg_match_all('/!\[(?:[^\]]*)\]\(([^)]+)\)/', $content, $matches)) {
            $imageUrls = array_merge($imageUrls, $matches[1]);
        }

        // 4. Extract direct image URLs (ending with image extensions)
        if (preg_match_all('/https?:\/\/[^\s<>"\']+\.(?:jpg|jpeg|png|gif|webp|bmp|svg)(?:\?[^\s<>"\']*)?/i', $content, $matches)) {
            $imageUrls = array_merge($imageUrls, $matches[0]);
        }

        // Remove duplicates and filter valid URLs
        $imageUrls = array_unique($imageUrls);
        $imageUrls = array_filter($imageUrls, function ($url) {
            return filter_var($url, FILTER_VALIDATE_URL) !== false;
        });

        // Log extracted images
        if (!empty($imageUrls)) {
            $this->logger->debug('[Content Extractor] Extracted images from content', [
                'count' => count($imageUrls),
                'urls' => $imageUrls,
            ]);
        }

        return array_values($imageUrls);
    }

    /**
     * Download image and convert to base64 data URI.
     *
     * @param string $url
     * @return string|null Base64 data URI or null if download fails
     */
    private function downloadImage(string $url): ?string
    {
        try {
            $client = new Client([
                'timeout' => self::IMAGE_DOWNLOAD_TIMEOUT,
                'connect_timeout' => 5,
            ]);

            $response = $client->get($url, [
                'headers' => [
                    'User-Agent' => 'Flarum-OpenAI-Content-Audit/1.0',
                ],
            ]);

            $contentLength = $response->getHeader('Content-Length')[0] ?? 0;
            if ($contentLength > self::MAX_IMAGE_SIZE) {
                $this->logger->warning('[Content Extractor] Image too large', [
                    'url' => $url,
                    'size' => $contentLength,
                ]);
                return null;
            }

            $body = (string) $response->getBody();
            $contentType = $response->getHeader('Content-Type')[0] ?? 'image/jpeg';

            // Verify it's an image
            if (!str_starts_with($contentType, 'image/')) {
                $this->logger->warning('[Content Extractor] URL is not an image', [
                    'url' => $url,
                    'content_type' => $contentType,
                ]);
                return null;
            }

            $base64 = base64_encode($body);
            return "data:{$contentType};base64,{$base64}";
        } catch (GuzzleException $e) {
            $this->logger->warning('[Content Extractor] Failed to download image', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        } catch (\Exception $e) {
            $this->logger->error('[Content Extractor] Unexpected error downloading image', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
