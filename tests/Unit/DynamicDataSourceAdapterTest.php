<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Tests\Unit;

use ElgiborSolution\AdvancedReports\Bridge\DynamicDataSourceAdapter;
use ElgiborSolution\AdvancedReports\Sources\ReportField;
use ElgiborSolution\AdvancedReports\Sources\ReportParameter;
use ElgiborSolution\AdvancedReports\Tests\TestCase;
use ESolution\DataSources\Models\DataSource;
use ESolution\DataSources\Models\DataSourceParameter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unit tests for DynamicDataSourceAdapter — verifies that a DataSource model
 * is correctly translated into a ReportSourceContract implementation.
 */
class DynamicDataSourceAdapterTest extends TestCase
{
    protected DataSource $dataSource;

    protected DataSource $customQuerySource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createDataSourceTables();
        $this->createTestTable();
        $this->seedDataSources();
    }

    protected function createDataSourceTables(): void
    {
        if (! Schema::hasTable('data_sources')) {
            Schema::create('data_sources', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('table_name');
                $table->boolean('use_custom_query')->default(false);
                $table->json('columns')->nullable();
                $table->text('custom_query')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('data_source_parameters')) {
            Schema::create('data_source_parameters', function ($table) {
                $table->id();
                $table->foreignId('data_source_id')->constrained('data_sources')->onDelete('cascade');
                $table->string('param_name');
                $table->string('param_type')->default('string');
                $table->string('param_default_value')->nullable();
                $table->boolean('is_required')->default(true);
                $table->timestamps();
            });
        }
    }

    protected function createTestTable(): void
    {
        if (! Schema::hasTable('invoices')) {
            Schema::create('invoices', function ($table) {
                $table->id();
                $table->string('invoice_number');
                $table->string('client_name');
                $table->decimal('total', 12, 2);
                $table->integer('quantity');
                $table->boolean('is_paid');
                $table->date('invoice_date');
                $table->timestamps();
            });
        }
    }

    protected function seedDataSources(): void
    {
        // Table-based data source.
        $this->dataSource = DataSource::create([
            'name' => 'Invoices Report',
            'table_name' => 'invoices',
            'use_custom_query' => false,
            'columns' => ['invoice_number', 'client_name', 'total', 'quantity', 'is_paid', 'invoice_date'],
        ]);

        DataSourceParameter::create([
            'data_source_id' => $this->dataSource->id,
            'param_name' => 'start_date',
            'param_type' => 'date',
            'param_default_value' => '2026-01-01',
            'is_required' => true,
        ]);

        DataSourceParameter::create([
            'data_source_id' => $this->dataSource->id,
            'param_name' => 'client_filter',
            'param_type' => 'string',
            'param_default_value' => null,
            'is_required' => false,
        ]);

        // Custom-query data source.
        $this->customQuerySource = DataSource::create([
            'name' => 'Custom Summary',
            'table_name' => 'invoices',
            'use_custom_query' => true,
            'columns' => ['client_name', 'total_amount', 'invoice_count'],
            'custom_query' => 'SELECT client_name, SUM(total) as total_amount, COUNT(*) as invoice_count FROM invoices WHERE invoice_date >= :start_date GROUP BY client_name',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Tests
    |--------------------------------------------------------------------------
    */

    public function test_key_returns_dynamic_prefix_with_id(): void
    {
        $adapter = new DynamicDataSourceAdapter($this->dataSource);

        $this->assertEquals("dynamic:{$this->dataSource->id}", $adapter->key());
    }

    public function test_label_returns_datasource_name(): void
    {
        $adapter = new DynamicDataSourceAdapter($this->dataSource);

        $this->assertEquals('Invoices Report', $adapter->label());
    }

    public function test_fields_derived_from_columns_json(): void
    {
        $adapter = new DynamicDataSourceAdapter($this->dataSource);

        $fields = $adapter->fields();

        // Should have all 6 columns.
        $this->assertCount(6, $fields);

        // Each field should be a ReportField instance.
        $this->assertInstanceOf(ReportField::class, $fields->get('invoice_number'));
        $this->assertInstanceOf(ReportField::class, $fields->get('total'));
        $this->assertInstanceOf(ReportField::class, $fields->get('is_paid'));
        $this->assertInstanceOf(ReportField::class, $fields->get('invoice_date'));

        // Verify field properties.
        $invoiceField = $fields->get('invoice_number');
        $this->assertEquals('invoice_number', $invoiceField->key);
        $this->assertEquals('Invoice Number', $invoiceField->label); // humanized
        $this->assertEquals('string', $invoiceField->type);
        $this->assertTrue($invoiceField->sortable);
        $this->assertTrue($invoiceField->filterable);
        $this->assertFalse($invoiceField->aggregatable);
        $this->assertFalse($invoiceField->hidden);

        // Numeric field should be aggregatable.
        $totalField = $fields->get('total');
        $this->assertEquals('decimal', $totalField->type);
        $this->assertTrue($totalField->aggregatable);

        $quantityField = $fields->get('quantity');
        $this->assertEquals('integer', $quantityField->type);
        $this->assertTrue($quantityField->aggregatable);

        // Boolean field.
        $paidField = $fields->get('is_paid');
        $this->assertEquals('boolean', $paidField->type);
        $this->assertFalse($paidField->aggregatable);

        // Date field.
        $dateField = $fields->get('invoice_date');
        $this->assertEquals('date', $dateField->type);
        $this->assertFalse($dateField->aggregatable);
    }

    public function test_parameters_derived_from_datasource_parameters(): void
    {
        $adapter = new DynamicDataSourceAdapter($this->dataSource);

        $parameters = $adapter->parameters();

        $this->assertCount(2, $parameters);

        // Verify start_date parameter.
        $startDate = $parameters->get('start_date');
        $this->assertInstanceOf(ReportParameter::class, $startDate);
        $this->assertEquals('start_date', $startDate->name);
        $this->assertEquals('date', $startDate->type);
        $this->assertTrue($startDate->required);
        $this->assertEquals('2026-01-01', $startDate->default);

        // Verify client_filter parameter.
        $clientFilter = $parameters->get('client_filter');
        $this->assertInstanceOf(ReportParameter::class, $clientFilter);
        $this->assertEquals('client_filter', $clientFilter->name);
        $this->assertEquals('string', $clientFilter->type);
        $this->assertFalse($clientFilter->required);
        $this->assertNull($clientFilter->default);
    }

    public function test_query_builds_from_table_name(): void
    {
        $adapter = new DynamicDataSourceAdapter($this->dataSource);

        // Insert test data.
        DB::table('invoices')->insert([
            ['invoice_number' => 'INV-001', 'client_name' => 'Acme', 'total' => 1000, 'quantity' => 5, 'is_paid' => true, 'invoice_date' => '2026-01-15', 'created_at' => now(), 'updated_at' => now()],
            ['invoice_number' => 'INV-002', 'client_name' => 'Globex', 'total' => 2000, 'quantity' => 3, 'is_paid' => false, 'invoice_date' => '2026-02-01', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $query = $adapter->query();

        // Should return a query builder that can be executed.
        $this->assertInstanceOf(\Illuminate\Contracts\Database\Query\Builder::class, $query);

        // Execute it and verify results.
        $results = $query->get();
        $this->assertCount(2, $results);
        $this->assertEquals('INV-001', $results->first()->invoice_number);
    }

    public function test_query_builds_from_custom_query(): void
    {
        $adapter = new DynamicDataSourceAdapter($this->customQuerySource);

        // Insert test data.
        DB::table('invoices')->insert([
            ['invoice_number' => 'INV-001', 'client_name' => 'Acme', 'total' => 1000, 'quantity' => 5, 'is_paid' => true, 'invoice_date' => '2026-01-15', 'created_at' => now(), 'updated_at' => now()],
            ['invoice_number' => 'INV-002', 'client_name' => 'Acme', 'total' => 2000, 'quantity' => 3, 'is_paid' => false, 'invoice_date' => '2026-02-01', 'created_at' => now(), 'updated_at' => now()],
            ['invoice_number' => 'INV-003', 'client_name' => 'Globex', 'total' => 500, 'quantity' => 1, 'is_paid' => true, 'invoice_date' => '2026-03-01', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // The custom query uses :start_date parameter.
        $query = $adapter->query(['start_date' => '2026-01-01']);

        $this->assertInstanceOf(\Illuminate\Contracts\Database\Query\Builder::class, $query);

        // Execute and verify grouped results.
        $results = $query->get();
        $this->assertGreaterThanOrEqual(1, $results->count());

        // Acme should have 2 invoices totaling 3000.
        $acmeRow = $results->firstWhere('client_name', 'Acme');
        $this->assertNotNull($acmeRow);
        $this->assertEquals(3000, (float) $acmeRow->total_amount);
        $this->assertEquals(2, (int) $acmeRow->invoice_count);
    }

    public function test_prefixed_connection_uses_configured_physical_name_without_double_prefixing(): void
    {
        config()->set('database.connections.testing.prefix', 'tenant_');
        DB::purge('testing');

        Schema::connection('testing')->create('report_invoices', function ($table) {
            $table->id();
            $table->decimal('grand_total', 12, 2);
        });
        DB::connection('testing')->table('report_invoices')->insert(['grand_total' => 1250.50]);

        $source = new DataSource([
            'name' => 'Tenant invoices',
            // Data Sources stores the physical table name returned by metadata.
            'table_name' => 'tenant_report_invoices',
            'database_scope' => 'tenant',
            'use_custom_query' => false,
            'columns' => ['grand_total'],
        ]);
        $source->id = 99;

        $adapter = new DynamicDataSourceAdapter($source);
        $field = $adapter->fields()->get('grand_total');

        $this->assertSame('decimal', $field->type);
        $this->assertTrue($field->aggregatable);
        $this->assertSame(1250.50, (float) $adapter->query()->first()->grand_total);
    }

    public function test_connection_without_prefix_uses_configured_table_name(): void
    {
        $adapter = new DynamicDataSourceAdapter($this->dataSource);

        $this->assertSame('', DB::connection()->getTablePrefix());
        $this->assertSame('decimal', $adapter->fields()->get('total')->type);
        $this->assertStringContainsString('"invoices"', $adapter->query()->toSql());
    }

    public function test_description_includes_table_name_for_table_source(): void
    {
        $adapter = new DynamicDataSourceAdapter($this->dataSource);

        $description = $adapter->description();
        $this->assertStringContainsString('invoices', $description);
        $this->assertStringContainsString('Invoices Report', $description);
    }

    public function test_description_indicates_custom_query_for_query_source(): void
    {
        $adapter = new DynamicDataSourceAdapter($this->customQuerySource);

        $description = $adapter->description();
        $this->assertStringContainsString('custom query', $description);
        $this->assertStringContainsString('Custom Summary', $description);
    }

    public function test_fields_fallback_to_string_type_for_custom_query_sources(): void
    {
        $adapter = new DynamicDataSourceAdapter($this->customQuerySource);

        $fields = $adapter->fields();

        // Custom query sources can't infer types — should default to 'string'.
        foreach ($fields as $field) {
            $this->assertEquals('string', $field->type);
        }
    }

    public function test_fields_are_cached_on_repeated_calls(): void
    {
        $adapter = new DynamicDataSourceAdapter($this->dataSource);

        $first = $adapter->fields();
        $second = $adapter->fields();

        $this->assertSame($first, $second);
    }

    public function test_parameters_are_cached_on_repeated_calls(): void
    {
        $adapter = new DynamicDataSourceAdapter($this->dataSource);

        $first = $adapter->parameters();
        $second = $adapter->parameters();

        $this->assertSame($first, $second);
    }

    public function test_get_data_source_returns_underlying_model(): void
    {
        $adapter = new DynamicDataSourceAdapter($this->dataSource);

        $this->assertSame($this->dataSource, $adapter->getDataSource());
    }
}
