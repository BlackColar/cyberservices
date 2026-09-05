<?php
if (!defined('ABSPATH')) exit;

class Cyber_Hub_DB {

    const SCHEMA_VERSION = '3';

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'cyber_service_assessments';
    }

    public static function create_tables() {
        global $wpdb;
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            submitted_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            service_type varchar(50) NOT NULL,
            company_name varchar(600) NOT NULL,
            contact_person varchar(600) NOT NULL,
            contact_email varchar(600) NOT NULL,
            contact_phone varchar(600) NOT NULL,
            company_address text,
            business_description text,
            service_data longtext,
            nda_agreed tinyint(1) DEFAULT 0 NOT NULL,
            nda_version varchar(32) DEFAULT '' NOT NULL,
            nda_agreed_at datetime NULL,
            attachment_url text,
            status varchar(50) DEFAULT 'new' NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        update_option('cyber_hub_db_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function maybe_upgrade() {
        if (get_option('cyber_hub_db_schema_version') !== self::SCHEMA_VERSION) {
            self::create_tables();
            // dbDelta không đáng tin khi đổi kích thước cột trên bảng đã tồn tại,
            // chạy ALTER trực tiếp để đảm bảo các cột mã hóa đủ rộng.
            self::widen_encrypted_columns();
        }
        self::migrate_legacy_pii();
    }

    // Dữ liệu AES-256-GCM dạng "v2:" + base64(JSON) dài ~150-300 ký tự,
    // vượt xa varchar(50)/varchar(100) cũ → MySQL strict mode từ chối INSERT.
    private static function widen_encrypted_columns() {
        global $wpdb;
        $table_name = self::get_table_name();
        $alters = [
            "ALTER TABLE $table_name MODIFY company_name varchar(600) NOT NULL",
            "ALTER TABLE $table_name MODIFY contact_person varchar(600) NOT NULL",
            "ALTER TABLE $table_name MODIFY contact_email varchar(600) NOT NULL",
            "ALTER TABLE $table_name MODIFY contact_phone varchar(600) NOT NULL",
        ];
        foreach ($alters as $sql) {
            $wpdb->query($sql); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        }
    }

    // Migrate a small batch per request, avoiding a timeout on sites with many records.
    public static function migrate_legacy_pii() {
        global $wpdb;
        $table_name = self::get_table_name();
        $cursor = (int) get_option('cyber_hub_pii_migration_cursor', 0);
        if ($cursor === -1) return;
        $fields = ['company_name', 'contact_person', 'contact_email', 'contact_phone', 'company_address', 'business_description'];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, " . implode(', ', $fields) . " FROM $table_name WHERE id > %d ORDER BY id ASC LIMIT 50",
            $cursor
        ));
        if (empty($rows)) {
            update_option('cyber_hub_pii_migration_cursor', -1, false);
            return;
        }
        foreach ($rows as $row) {
            $data = [];
            foreach ($fields as $field) {
                if (strpos((string) $row->$field, 'v2:') !== 0) {
                    $data[$field] = Cyber_Hub_Security::encrypt($row->$field);
                }
            }
            if ($data) $wpdb->update($table_name, $data, ['id' => $row->id]);
            $cursor = (int) $row->id;
        }
        update_option('cyber_hub_pii_migration_cursor', $cursor, false);
    }
}
