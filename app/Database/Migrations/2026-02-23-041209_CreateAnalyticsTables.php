<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAnalyticsTables extends Migration
{
    public function up()
    {
        // Event Type Daily Metrics
        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'date' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'event_type' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => false,
            ],
            'count' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'default' => 1,
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->createTable('metrics_event_type_daily');

        // Create unique index for upsert
        $this->db->query('CREATE UNIQUE INDEX idx_event_type_unique ON metrics_event_type_daily (date, event_type)');

        // Page Daily Metrics
        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'date' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'url' => [
                'type' => 'VARCHAR',
                'constraint' => 500,
                'null' => false,
            ],
            'count' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'default' => 1,
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->createTable('metrics_page_daily');

        $this->db->query('CREATE UNIQUE INDEX idx_page_daily_unique ON metrics_page_daily (date, url(255))');

        // User Daily Metrics
        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'date' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'user_id' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => false,
            ],
            'event_count' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'default' => 1,
            ],
            'last_active_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->createTable('metrics_user_daily');

        $this->db->query('CREATE UNIQUE INDEX idx_user_daily_unique ON metrics_user_daily (date, user_id)');

        // Dead Letter Queue Log
        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'event_id' => [
                'type' => 'VARCHAR',
                'constraint' => 36,
                'null' => true,
            ],
            'event_data' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'error_message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'retry_count' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'default' => 0,
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->createTable('dead_letter_queue');
    }

    public function down()
    {
        $this->forge->dropTable('dead_letter_queue');
        $this->forge->dropTable('metrics_user_daily');
        $this->forge->dropTable('metrics_page_daily');
        $this->forge->dropTable('metrics_event_type_daily');
    }
}
