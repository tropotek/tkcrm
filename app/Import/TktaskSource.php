<?php

namespace App\Import;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The tktask database being imported: the `tktask` connection in config/database.php, set by the
 * TKTASK_DB_* environment variables. It is a copy of the live database loaded into tktask's own
 * dev stack, never the live server. Nothing is ever written to it.
 */
class TktaskSource
{
    public const CONNECTION = 'tktask';

    /**
     * Every tktask table the importer knows, with the columns it knows in each, as tktask's own
     * migrations make them. A table or column in the database that is not here is reported, so a
     * live schema that has drifted from the repo is seen before anything is trusted.
     */
    public const SCHEMA = [
        'user' => ['user_id', 'type', 'title', 'given_name', 'family_name', 'phone', 'address', 'city', 'state', 'postcode', 'country', 'template', 'image', 'modified', 'created'],
        'auth' => ['auth_id', 'uid', 'fkey', 'fid', 'permissions', 'username', 'password', 'email', 'timezone', 'active', 'external', 'session_id', 'last_login', 'modified', 'created'],
        'registry' => ['key', 'value'],
        'company' => ['company_id', 'type', 'name', 'alias', 'abn', 'website', 'contact', 'phone', 'email', 'accounts_email', 'address', 'credit', 'notes', 'active', 'modified', 'created'],
        'product_category' => ['product_category_id', 'name', 'description', 'active', 'modified', 'created'],
        'product' => ['product_id', 'product_category_id', 'cycle', 'name', 'code', 'price', 'description', 'notes', 'active', 'modified', 'created'],
        'project' => ['project_id', 'user_id', 'company_id', 'name', 'quote', 'start_on', 'end_on', 'cancelled_on', 'description', 'notes', 'modified', 'created'],
        'task_category' => ['task_category_id', 'name', 'description', 'order_by', 'active', 'modified', 'created'],
        'task' => ['task_id', 'company_id', 'project_id', 'task_category_id', 'creator_user_id', 'assigned_user_id', 'closed_user_id', 'subject', 'comments', 'priority', 'minutes', 'closed_at', 'cancelled_at', 'invoiced_at', 'invoice_item_id', 'modified', 'created'],
        'task_log' => ['task_log_id', 'task_id', 'user_id', 'product_id', 'billable', 'start_at', 'minutes', 'comment', 'modified', 'created'],
        'invoice' => ['invoice_id', 'fkey', 'fid', 'purchase_order', 'discount', 'tax', 'shipping', 'billing_address', 'issued_on', 'paid_on', 'cancelled_on', 'notes', 'modified', 'created'],
        'invoice_item' => ['invoice_item_id', 'invoice_id', 'product_code', 'description', 'qty', 'price', 'total', 'notes', 'modified', 'created'],
        'payment' => ['payment_id', 'invoice_id', 'amount', 'method', 'received_at', 'notes', 'modified', 'created'],
        'recurring' => ['recurring_id', 'company_id', 'product_id', 'price', 'count', 'cycle', 'start_on', 'end_on', 'prev_on', 'next_on', 'active', 'issue', 'description', 'notes', 'modified', 'created'],
        'expense_category' => ['expense_category_id', 'name', 'description', 'claim', 'active', 'modified', 'created'],
        'expense' => ['expense_id', 'expense_category_id', 'company_id', 'invoice_no', 'receipt_no', 'description', 'purchased_on', 'total', 'modified', 'created'],
        'domain' => ['domain_id', 'company_id', 'url', 'notes', 'active', 'modified', 'created'],
        'domain_ping' => ['domain_ping_id', 'domain_id', 'status', 'site_name', 'site_time', 'timezone', 'bytes', 'created'],
        'file' => ['file_id', 'user_id', 'fkey', 'fid', 'label', 'filename', 'bytes', 'mime', 'notes', 'selected', 'created'],
        // Known, and deliberately not imported.
        'project_user' => ['project_id', 'user_id'],
        'notify' => ['notify_id', 'user_id', 'title', 'message', 'url', 'icon', 'read_at', 'notified_at', 'ttl_mins', 'expiry', 'created'],
        'status_log' => ['status_log_id', 'user_id', 'fkey', 'fid', 'name', 'notify', 'message', 'data', 'created'],
        'auth_remember' => ['id', 'selector', 'hashed_validator', 'browser_id', 'auth_id', 'ttl_mins', 'created', 'expiry'],
        'auth_login_attempt' => ['attempt_id', 'username', 'ip', 'created'],
        'guest_token' => ['token', 'pages', 'payload', 'ttl_mins', 'created', 'expiry'],
    ];

    /** The tables that hold no business data and are not imported. */
    public const NOT_IMPORTED = ['project_user', 'notify', 'status_log', 'auth_remember', 'auth_login_attempt', 'guest_token'];

    /** A database without these is not tktask's. */
    private const MUST_HAVE = ['company', 'invoice', 'invoice_item', 'task', 'user', 'auth'];

    /**
     * Open the connection and check that it looks like tktask.
     *
     * @throws RuntimeException when it cannot be reached or a table is missing
     */
    public function connect(): Connection
    {
        $db = $this->db();
        $tables = $this->tables();
        $missing = array_values(array_diff(self::MUST_HAVE, $tables));

        if ($missing !== []) {
            throw new RuntimeException('This is not a tktask database: it has no '.implode(', ', $missing).' table.');
        }

        return $db;
    }

    public function db(): Connection
    {
        return DB::connection(self::CONNECTION);
    }

    /**
     * How many rows each table the importer knows has, for those the database has.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $tables = $this->tables();

        return collect(array_keys(self::SCHEMA))
            ->filter(fn (string $table) => in_array($table, $tables, true))
            ->mapWithKeys(fn (string $table) => [$table => $this->db()->table($table)->count()])
            ->all();
    }

    /**
     * Where the database differs from the tktask the importer was written against, a line each:
     * a table or column it does not know, and one it expects that is not there. tktask's own
     * housekeeping tables, whose names begin with an underscore, are passed over.
     *
     * @return list<string>
     */
    public function unrecognised(): array
    {
        $differences = [];
        $tables = $this->tables();

        foreach ($tables as $table) {
            if (str_starts_with($table, '_')) {
                continue;
            }

            if (! isset(self::SCHEMA[$table])) {
                $differences[] = "Table {$table} is not one the importer knows. Nothing is read from it.";

                continue;
            }

            $columns = $this->db()->getSchemaBuilder()->getColumnListing($table);

            foreach (array_diff($columns, self::SCHEMA[$table]) as $column) {
                $differences[] = "Column {$table}.{$column} is not one the importer knows. Nothing is read from it.";
            }

            foreach (array_diff(self::SCHEMA[$table], $columns) as $column) {
                $differences[] = "Column {$table}.{$column} is expected and is not there.";
            }
        }

        foreach (array_diff(array_keys(self::SCHEMA), $tables) as $table) {
            $differences[] = "Table {$table} is expected and is not there.";
        }

        return $differences;
    }

    /**
     * Where the source is, without its password, to show the operator.
     */
    public function describe(): string
    {
        $config = $this->db()->getConfig();

        return ($config['driver'] ?? '') === 'sqlite'
            ? "sqlite {$config['database']}"
            : "{$config['driver']} {$config['username']}@{$config['host']}:{$config['port']}/{$config['database']}";
    }

    /**
     * The database's own tables. Views are not tables.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        // Only the database named: a MariaDB login may see other databases beside it.
        $schema = $this->db()->getDriverName() === 'sqlite' ? null : $this->db()->getDatabaseName();

        return array_values(array_map(fn (array $table) => $table['name'], $this->db()->getSchemaBuilder()->getTables($schema)));
    }
}
