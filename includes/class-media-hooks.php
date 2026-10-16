<?php
namespace Folderify;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Media_Hooks
 * 
 * Handles integrating the custom media folder UI with the WordPress Backbone.js media modal.
 */
class Media_Hooks
{

    public function __construct()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_media_scripts']);
        // Extend wp.media.view.AttachmentsBrowser, etc via JS.
        // We need to inject our react app into the media modal as well.

        add_filter('attachment_fields_to_edit', [$this, 'add_attachment_fields'], 10, 2);
        add_filter('media_row_actions', [$this, 'add_media_row_actions'], 10, 2);
    }

    public function enqueue_media_scripts($hook)
    {
        // Script enqueue for media modal is now handled by class-assets.php via build/index.js
        // We no longer enqueue the raw ES6 module directly.
    }

    /**
     * Add "Replace media" field to the attachment editor.
     */
    public function add_attachment_fields($form_fields, $post)
    {
        if (!current_user_can('edit_post', $post->ID)) {
            return $form_fields;
        }

        $url = wp_get_attachment_url($post->ID);
        $name = basename(get_attached_file($post->ID));

        $replace_url = add_query_arg([
            'page' => 'folderify-replace',
            'attachment_id' => $post->ID,
        ], admin_url('admin.php'));

        $form_fields['folderify_replace'] = [
            'label' => __('Replace media', 'folderify'),
            'input' => 'html',
            'html' => sprintf(
                '<a href="%s" class="button button-secondary">%s</a>
                 <p class="description">%s</p>',
                esc_url($replace_url),
                __('Upload a new file', 'folderify'),
                __('To replace the current file, click the button and upload a replacement file.', 'folderify')
            ),
        ];

        return $form_fields;
    }

    /**
     * Add "Replace media" link to media library row actions (List view).
     */
    public function add_media_row_actions($actions, $post)
    {
        if ($post->post_type !== 'attachment' || !current_user_can('edit_post', $post->ID)) {
            return $actions;
        }

        $url = wp_get_attachment_url($post->ID);
        $name = basename(get_attached_file($post->ID));

        $replace_url = add_query_arg([
            'page' => 'folderify-replace',
            'attachment_id' => $post->ID,
        ], admin_url('admin.php'));

        $actions['folderify_replace'] = sprintf(
            '<a href="%s" aria-label="%s">%s</a>',
            esc_url($replace_url),
            esc_attr__('Replace media', 'folderify'),
            __('Replace media', 'folderify')
        );

        return $actions;
    }
}
