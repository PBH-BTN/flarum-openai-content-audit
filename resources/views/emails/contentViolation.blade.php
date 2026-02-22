{!! $translator->trans('ghostchu-openai-content-audit.email.body.contentViolation', [
    '{recipient_display_name}' => $user->display_name,
    '{content_type}' => $blueprint->auditLog->content_type === 'post' ? $translator->trans('ghostchu-openai-content-audit.email.content_type.post') :
                         ($blueprint->auditLog->content_type === 'discussion' ? $translator->trans('ghostchu-openai-content-audit.email.content_type.discussion') :
                         ($blueprint->auditLog->content_type === 'user_profile' ? $translator->trans('ghostchu-openai-content-audit.email.content_type.user_profile') : $blueprint->auditLog->content_type)),
    '{conclusion}' => $blueprint->auditLog->conclusion,
    '{confidence}' => number_format($blueprint->auditLog->confidence * 100, 1) . '%',
    '{forum_url}' => $url->to('forum')->base(),
]) !!}
