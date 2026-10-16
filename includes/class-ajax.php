<?php
namespace Folderify;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class AJAX
 *
 * Registers AJAX endpoints for legacy comp or specific media modal integrations.
 */
class AJAX
{

    /**
     * @var Folder_Manager
     */
    private $folder_manager;

    public function __construct($folder_manager)
    {
        $this->folder_manager = $folder_manager;

        $ajax_actions = [
            'create_folder',
            'rename_folder',
            'delete_folder',
            'move_folder',
            'assign_media',
        ];

        foreach ($ajax_actions as $action) {
            add_action('wp_ajax_folderify_' . $action, [$this, $action]);
        }
    }

    private function verify_nonce()
    {
        check_ajax_referer('folderify_ajax', 'nonce');

        if (!Permissions::can_edit_folders()) {
            wp_send_json_error(['message' => __('Permission denied.', 'folderify')]);
        }
    }

    public function create_folder()
    {
        $this->verify_nonce();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in verify_nonce() via check_ajax_referer().
        $name = isset($_POST['name']) ? folderify_sanitize_folder_name(wp_unslash($_POST['name'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $parent_id = isset($_POST['parent_id']) ? absint(wp_unslash($_POST['parent_id'])) : 0;

        $id = $this->folder_manager->create_folder(['name' => $name, 'parent_id' => $parent_id]);

        if (is_wp_error($id)) {
            wp_send_json_error(['message' => $id->get_error_message()]);
        }

        wp_send_json_success($this->folder_manager->get_folder($id));
    }

    public function rename_folder()
    {
        $this->verify_nonce();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_nonce() via check_ajax_referer().
        $id = isset($_POST['id']) ? absint(wp_unslash($_POST['id'])) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in verify_nonce().
        $name = isset($_POST['name']) ? folderify_sanitize_folder_name(wp_unslash($_POST['name'])) : '';

        $result = $this->folder_manager->update_folder($id, ['name' => $name]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($this->folder_manager->get_folder($id));
    }

    public function delete_folder()
    {
        $this->verify_nonce();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_nonce() via check_ajax_referer().
        $id = isset($_POST['id']) ? absint(wp_unslash($_POST['id'])) : 0;

        $result = $this->folder_manager->delete_folder($id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success();
    }

    public function move_folder()
    {
        $this->verify_nonce();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_nonce() via check_ajax_referer().
        $id = isset($_POST['id']) ? absint(wp_unslash($_POST['id'])) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $parent_id = isset($_POST['parent_id']) ? absint(wp_unslash($_POST['parent_id'])) : 0;

        $result = $this->folder_manager->update_folder($id, ['parent_id' => $parent_id]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($this->folder_manager->get_folder($id));
    }

    public function assign_media()
    {
        $this->verify_nonce();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_nonce() via check_ajax_referer().
        $attachments = isset($_POST['attachments']) ? array_map('absint', (array) wp_unslash($_POST['attachments'])) : [];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $folder_id = isset($_POST['folder_id']) ? absint(wp_unslash($_POST['folder_id'])) : 0;

        if (empty($attachments)) {
            wp_send_json_error(['message' => __('No attachments provided.', 'folderify')]);
        }

        $this->folder_manager->set_attachments_folders($attachments, [$folder_id]);

        wp_send_json_success();
    }
}
