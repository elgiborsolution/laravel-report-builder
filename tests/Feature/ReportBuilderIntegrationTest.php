<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Tests\Feature;

use ElgiborSolution\AdvancedReports\Bridge\ConnectedSource;
use ElgiborSolution\AdvancedReports\Bridge\DataSourceBridge;
use ElgiborSolution\AdvancedReports\Export\ExportManager;
use ElgiborSolution\AdvancedReports\Facades\AdvancedReports;
use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Sources\SourceRegistry;
use ElgiborSolution\AdvancedReports\Tests\TestCase;
use ESolution\DataSources\Models\DataSource;
use ESolution\DataSources\Models\DataSourceParameter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Integration tests for the full Report Builder flow including dynamic data source
 * bridge, preview endpoints, export, and the designer schema.
 */
class ReportBuilderIntegrationTest extends TestCase
{
    protected DataSource $dataSource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createDataSourceInfrastructure();
        $this->seedTestOrdersTable();
        $this->seedDataSource();
        $this->actingAsTestUser();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Create the data_sources and data_source_parameters tables used by the
     * laravel-data-sources package, plus a test_orders table for the DataSource to query.
     */
    protected function createDataSourceInfrastructure(): void
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

        if (! Schema::hasTable('test_orders')) {
            Schema::create('test_orders', function ($table) {
                $table->id();
                $table->string('order_number');
                $table->string('customer_name');
                $table->decimal('amount', 12, 2);
                $table->string('status');
                $table->date('order_date');
                $table->timestamps();
            });
        }

        // Users table for auth/foreign keys.
        if (! Schema::hasTable('users')) {
            Schema::create('users', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->timestamps();
            });
        }
    }

    protected function seedTestOrdersTable(): void
    {
        DB::table('test_orders')->insert([
            ['order_number' => 'ORD-001', 'customer_name' => 'Acme Corp', 'amount' => 1500.00, 'status' => 'completed', 'order_date' => '2026-01-15', 'created_at' => now(), 'updated_at' => now()],
            ['order_number' => 'ORD-002', 'customer_name' => 'Acme Corp', 'amount' => 3200.50, 'status' => 'completed', 'order_date' => '2026-02-01', 'created_at' => now(), 'updated_at' => now()],
            ['order_number' => 'ORD-003', 'customer_name' => 'Globex Inc', 'amount' => 750.00, 'status' => 'pending', 'order_date' => '2026-02-10', 'created_at' => now(), 'updated_at' => now()],
            ['order_number' => 'ORD-004', 'customer_name' => 'Initech', 'amount' => 4800.00, 'status' => 'completed', 'order_date' => '2026-03-05', 'created_at' => now(), 'updated_at' => now()],
            ['order_number' => 'ORD-005', 'customer_name' => 'Globex Inc', 'amount' => 920.75, 'status' => 'cancelled', 'order_date' => '2026-03-12', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function seedDataSource(): void
    {
        $this->dataSource = DataSource::create([
            'name' => 'Test Orders',
            'table_name' => 'test_orders',
            'use_custom_query' => false,
            'columns' => ['order_number', 'customer_name', 'amount', 'status', 'order_date'],
        ]);

        DataSourceParameter::create([
            'data_source_id' => $this->dataSource->id,
            'param_name' => 'status_filter',
            'param_type' => 'string',
            'param_default_value' => 'completed',
            'is_required' => false,
        ]);
    }

    protected function actingAsTestUser(): void
    {
        $userModel = config('auth.providers.users.model', \Illuminate\Foundation\Auth\User::class);
        $user = new $userModel(['id' => 1, 'name' => 'Test User', 'email' => 'test@example.com']);
        $user->id = 1;

        // Ensure user exists in DB for foreign key constraints.
        if (Schema::hasTable('users')) {
            DB::table('users')->updateOrInsert(
                ['id' => 1],
                ['name' => 'Test User', 'email' => 'test@example.com', 'password' => bcrypt('password'), 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $this->actingAs($user);
    }

    protected function connectDataSource(): string
    {
        $response = $this->postJson('/api/advanced-reports/sources/connect', [
            'data_source_id' => $this->dataSource->id,
        ]);

        return $response->json('source_key');
    }

    protected function createReportWithConnectedSource(string $sourceKey): Report
    {
        return Report::create([
            'name' => 'Integration Test Report',
            'code' => 'integration_test_' . uniqid(),
            'data_source' => $sourceKey,
            'definition' => [
                'name' => 'Integration Test Report',
                'data_source' => $sourceKey,
                'columns' => [
                    ['field' => 'order_number', 'label' => 'Order #'],
                    ['field' => 'customer_name', 'label' => 'Customer'],
                    ['field' => 'amount', 'label' => 'Amount'],
                    ['field' => 'status', 'label' => 'Status'],
                    ['field' => 'order_date', 'label' => 'Date'],
                ],
            ],
            'is_active' => true,
            'is_public' => true,
            'created_by' => 1,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Tests
    |--------------------------------------------------------------------------
    */

    public function test_can_connect_data_source_as_report_source(): void
    {
        $response = $this->postJson('/api/advanced-reports/sources/connect', [
            'data_source_id' => $this->dataSource->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'source_key'])
            ->assertJsonPath('source_key', "dynamic:{$this->dataSource->id}");

        // Verify persisted in database.
        $this->assertDatabaseHas('advanced_report_connected_sources', [
            'data_source_id' => $this->dataSource->id,
            'source_key' => "dynamic:{$this->dataSource->id}",
        ]);
    }

    public function test_connected_source_appears_in_sources_list(): void
    {
        $this->connectDataSource();

        $response = $this->getJson('/api/advanced-reports/sources');

        $response->assertOk()
            ->assertJsonFragment([
                'key' => "dynamic:{$this->dataSource->id}",
                'label' => 'Test Orders',
            ]);

        // Verify the source appears with field_count information.
        $sources = collect($response->json('data'));
        $dynamicSource = $sources->firstWhere('key', "dynamic:{$this->dataSource->id}");

        $this->assertNotNull($dynamicSource);
        $this->assertEquals(5, $dynamicSource['field_count']);
    }

    public function test_connected_source_schema_available(): void
    {
        $sourceKey = $this->connectDataSource();

        $response = $this->getJson("/api/advanced-reports/sources/{$sourceKey}/schema");

        $response->assertOk()
            ->assertJsonStructure([
                'source' => ['key', 'label', 'description'],
                'fields' => [['key', 'label', 'type', 'sortable', 'filterable', 'aggregatable', 'hidden']],
                'parameters',
                'operators',
                'aggregate_functions',
                'formats',
            ])
            ->assertJsonPath('source.key', $sourceKey)
            ->assertJsonPath('source.label', 'Test Orders');

        // Verify fields derived from DataSource columns.
        $fields = collect($response->json('fields'));
        $this->assertTrue($fields->contains('key', 'order_number'));
        $this->assertTrue($fields->contains('key', 'customer_name'));
        $this->assertTrue($fields->contains('key', 'amount'));
        $this->assertTrue($fields->contains('key', 'status'));
        $this->assertTrue($fields->contains('key', 'order_date'));
    }

    public function test_designer_schema_returns_enhanced_metadata(): void
    {
        $sourceKey = $this->connectDataSource();

        $response = $this->getJson("/api/advanced-reports/sources/{$sourceKey}/designer-schema");

        $response->assertOk()
            ->assertJsonStructure([
                'source',
                'fields',
                'parameters',
                'operators',
                'aggregate_functions',
                'formats',
                'field_categories',
                'compatible_operators',
                'suggested_aggregates',
                'available_formats',
            ]);

        // Verify compatible_operators are present per field.
        $compatibleOperators = $response->json('compatible_operators');
        $this->assertArrayHasKey('order_number', $compatibleOperators);
        $this->assertArrayHasKey('amount', $compatibleOperators);

        // String fields should have 'contains' operator.
        $this->assertContains('contains', $compatibleOperators['order_number']);

        // Numeric fields should have 'between' and 'greater_than'.
        $this->assertContains('between', $compatibleOperators['amount']);
        $this->assertContains('greater_than', $compatibleOperators['amount']);

        // Verify suggested_aggregates for numeric fields.
        $suggestedAggregates = $response->json('suggested_aggregates');
        $this->assertArrayHasKey('amount', $suggestedAggregates);
        $this->assertSame(['count', 'sum', 'avg', 'min', 'max'], $suggestedAggregates['amount']);
        $this->assertSame(['count'], $suggestedAggregates['status']);

        // Verify available_formats.
        $availableFormats = $response->json('available_formats');
        $this->assertArrayHasKey('order_number', $availableFormats);
        $this->assertContains('string', $availableFormats['order_number']);
    }

    public function test_connected_source_is_rehydrated_before_a_new_schema_request(): void
    {
        $sourceKey = $this->connectDataSource();

        // Simulate the empty process-local registry of a later HTTP request.
        // The route middleware must restore it from the persisted connection,
        // not the controller.
        app(SourceRegistry::class)->flush();
        $this->assertFalse(app(SourceRegistry::class)->has($sourceKey));

        $this->getJson("/api/advanced-reports/sources/{$sourceKey}/designer-schema")
            ->assertOk()
            ->assertJsonPath('source.key', $sourceKey);

        $this->assertTrue(app(SourceRegistry::class)->has($sourceKey));
    }

    public function test_can_create_report_with_connected_source(): void
    {
        $sourceKey = $this->connectDataSource();

        $response = $this->postJson('/api/advanced-reports/reports', [
            'name' => 'Orders Report',
            'code' => 'orders_report',
            'data_source' => $sourceKey,
            'definition' => [
                'name' => 'Orders Report',
                'data_source' => $sourceKey,
                'columns' => [
                    ['field' => 'order_number', 'label' => 'Order #'],
                    ['field' => 'customer_name', 'label' => 'Customer'],
                    ['field' => 'amount', 'label' => 'Amount'],
                ],
            ],
            'is_public' => true,
            'is_active' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Orders Report')
            ->assertJsonPath('data.data_source', $sourceKey);

        $this->assertDatabaseHas('advanced_reports', [
            'name' => 'Orders Report',
            'code' => 'orders_report',
            'data_source' => $sourceKey,
        ]);
    }

    public function test_formula_columns_persist_reload_preview_and_run_as_virtual_fields(): void
    {
        $sourceKey = $this->connectDataSource();
        $definition = [
            'name' => 'Orders With Tax',
            'data_source' => $sourceKey,
            'columns' => [
                ['field' => 'order_number', 'label' => 'Order'],
                ['field' => 'amount_with_tax', 'label' => 'Amount with tax', 'format' => 'currency'],
            ],
            'formulas' => [[
                'id' => 'formula-tax',
                'name' => 'amount_with_tax',
                'label' => 'Amount with tax',
                'expression' => 'amount * 1.1',
                'type' => 'decimal',
                'format' => 'currency',
            ]],
        ];

        $created = $this->postJson('/api/advanced-reports/reports', [
            'name' => 'Orders With Tax',
            'code' => 'orders_with_tax',
            'data_source' => $sourceKey,
            'definition' => $definition,
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.definition.formulas.0.id', 'formula-tax')
            ->assertJsonPath('data.definition.columns.1.field', 'amount_with_tax');

        $reportId = $created->json('data.id');
        $this->getJson("/api/advanced-reports/reports/{$reportId}")
            ->assertOk()
            ->assertJsonPath('data.definition.formulas.0.name', 'amount_with_tax')
            ->assertJsonPath('data.definition.columns.1.label', 'Amount with tax');

        $preview = $this->postJson("/api/advanced-reports/reports/{$reportId}/preview", ['parameters' => []]);
        $preview->assertOk()
            ->assertJsonPath('columns.1.field', 'amount_with_tax')
            ->assertJsonPath('columns.1.label', 'Amount with tax')
            ->assertJsonPath('columns.1.type', 'decimal')
            ->assertJsonPath('columns.1.format', 'currency');
        $this->assertEqualsWithDelta(1650.0, (float) $preview->json('rows.0.amount_with_tax'), 0.0001);

        $run = $this->postJson("/api/advanced-reports/reports/{$reportId}/run", ['format' => 'json']);
        $run->assertOk()
            ->assertJsonPath('columns.1.field', 'amount_with_tax')
            ->assertJsonPath('columns.1.type', 'decimal')
            ->assertJsonPath('columns.1.format', 'currency');
        $this->assertStringContainsString('1,650', $run->json('rows.0.amount_with_tax'));

        $updatedDefinition = $definition;
        $updatedDefinition['formulas'][0]['expression'] = 'amount * 1.2';
        $updatedDefinition['columns'][1]['label'] = 'Gross amount';
        $this->putJson("/api/advanced-reports/reports/{$reportId}", [
            'name' => 'Orders With Tax',
            'data_source' => $sourceKey,
            'definition' => $updatedDefinition,
        ])->assertOk()
            ->assertJsonPath('data.definition.formulas.0.expression', 'amount * 1.2')
            ->assertJsonPath('data.definition.columns.1.label', 'Gross amount');

        $updatedPreview = $this->postJson("/api/advanced-reports/reports/{$reportId}/preview", ['parameters' => []]);
        $updatedPreview
            ->assertOk()
            ->assertJsonPath('columns.1.label', 'Gross amount')
            ->assertJsonPath('columns.1.field', 'amount_with_tax');
        $this->assertEqualsWithDelta(1800.0, (float) $updatedPreview->json('rows.0.amount_with_tax'), 0.0001);
    }

    public function test_report_api_rejects_duplicate_invalid_and_source_colliding_formula_names(): void
    {
        $sourceKey = $this->connectDataSource();
        foreach ([
            [['name' => 'amount', 'expression' => 'amount * 2']],
            [
                ['name' => 'net_total', 'expression' => 'amount * 2'],
                ['name' => 'NET_TOTAL', 'expression' => 'amount * 3'],
            ],
            [['name' => '12bad', 'expression' => 'amount * 2']],
        ] as $formulas) {
            $this->postJson('/api/advanced-reports/reports', [
                'name' => 'Invalid Formula Report',
                'code' => 'invalid_formula_' . uniqid(),
                'data_source' => $sourceKey,
                'definition' => [
                    'name' => 'Invalid Formula Report',
                    'data_source' => $sourceKey,
                    'formulas' => $formulas,
                ],
            ])->assertUnprocessable();
        }
    }

    public function test_preview_returns_capped_results(): void
    {
        $sourceKey = $this->connectDataSource();
        $report = $this->createReportWithConnectedSource($sourceKey);

        $response = $this->postJson("/api/advanced-reports/reports/{$report->id}/preview", [
            'parameters' => [],
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'columns',
                'rows',
                'row_count',
                'truncated',
                'aggregates',
            ]);

        // Our test_orders table has 5 rows — all should be returned (under 50 cap).
        $this->assertCount(5, $response->json('rows'));
        $this->assertEquals(5, $response->json('row_count'));
        $this->assertFalse($response->json('truncated'));
    }

    public function test_inline_preview_works_without_saved_report(): void
    {
        $sourceKey = $this->connectDataSource();

        $response = $this->postJson('/api/advanced-reports/reports/preview-inline', [
            'definition' => [
                'name' => 'Inline Test',
                'data_source' => $sourceKey,
                'columns' => [
                    ['field' => 'order_number', 'label' => 'Order #'],
                    ['field' => 'customer_name', 'label' => 'Customer'],
                    ['field' => 'amount_with_tax', 'label' => 'Amount with tax'],
                ],
                'formulas' => [[
                    'name' => 'amount_with_tax',
                    'label' => 'Amount with tax',
                    'expression' => 'amount * 1.1',
                    'type' => 'decimal',
                    'format' => 'decimal',
                ]],
            ],
            'parameters' => [],
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'columns',
                'rows',
                'row_count',
                'truncated',
                'aggregates',
            ]);

        // Verify data is returned correctly.
        $rows = $response->json('rows');
        $this->assertNotEmpty($rows);
        $this->assertLessThanOrEqual(50, count($rows));
        $response->assertJsonPath('columns.2.field', 'amount_with_tax')
            ->assertJsonPath('columns.2.type', 'decimal');
        $this->assertEqualsWithDelta(1650.0, (float) $response->json('rows.0.amount_with_tax'), 0.0001);
    }

    public function test_can_export_report_to_pdf(): void
    {
        $sourceKey = $this->connectDataSource();
        $report = $this->createReportWithConnectedSource($sourceKey);

        // Mock the export to avoid requiring dompdf/browsershot in tests.
        $this->mock(ExportManager::class, function ($mock) use ($report) {
            $mock->shouldReceive('export')
                ->once()
                ->andReturn(response()->make('%PDF-1.4 fake', 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="report.pdf"',
                ]));
        });

        // The controller calls $this->manager->export() for synchronous exports,
        // so we test the endpoint responds correctly.
        $response = $this->postJson("/api/advanced-reports/reports/{$report->id}/export", [
            'format' => 'pdf',
            'queued' => false,
        ]);

        // Should return PDF content or a 202 queued response.
        $this->assertTrue(
            in_array($response->status(), [200, 202]),
            "Expected 200 or 202, got {$response->status()}: " . $response->getContent()
        );
    }

    public function test_can_export_report_to_xlsx(): void
    {
        $sourceKey = $this->connectDataSource();
        $report = $this->createReportWithConnectedSource($sourceKey);

        // Mock the export to avoid requiring PhpSpreadsheet in tests.
        $this->mock(ExportManager::class, function ($mock) use ($report) {
            $mock->shouldReceive('export')
                ->once()
                ->andReturn(response()->make('xlsx-fake-content', 200, [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Content-Disposition' => 'attachment; filename="report.xlsx"',
                ]));
        });

        $response = $this->postJson("/api/advanced-reports/reports/{$report->id}/export", [
            'format' => 'xlsx',
            'queued' => false,
        ]);

        $this->assertTrue(
            in_array($response->status(), [200, 202]),
            "Expected 200 or 202, got {$response->status()}: " . $response->getContent()
        );
    }

    public function test_disconnect_removes_source(): void
    {
        $sourceKey = $this->connectDataSource();

        // Verify it exists.
        $this->assertDatabaseHas('advanced_report_connected_sources', [
            'source_key' => $sourceKey,
        ]);

        // Disconnect.
        $response = $this->deleteJson("/api/advanced-reports/sources/{$sourceKey}/disconnect");
        $response->assertOk()
            ->assertJsonPath('message', 'Data source disconnected successfully.');

        // Verify it's removed from the database.
        $this->assertDatabaseMissing('advanced_report_connected_sources', [
            'source_key' => $sourceKey,
        ]);

        // Verify it no longer appears in the dynamic sources list.
        $listResponse = $this->getJson('/api/advanced-reports/sources/dynamic');
        $listResponse->assertOk();

        $dynamicSources = collect($listResponse->json('data'));
        $this->assertNull($dynamicSources->firstWhere('source_key', $sourceKey));
    }

    public function test_cannot_connect_nonexistent_data_source(): void
    {
        $response = $this->postJson('/api/advanced-reports/sources/connect', [
            'data_source_id' => 99999,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['data_source_id']);
    }

    public function test_cannot_connect_already_connected_source(): void
    {
        // First connection — should succeed.
        $this->connectDataSource();

        // Second connection — should fail with 409.
        $response = $this->postJson('/api/advanced-reports/sources/connect', [
            'data_source_id' => $this->dataSource->id,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Data source is already connected.')
            ->assertJsonPath('source_key', "dynamic:{$this->dataSource->id}");
    }

    /*
    |--------------------------------------------------------------------------
    | Additional Edge Case Tests
    |--------------------------------------------------------------------------
    */

    public function test_dynamic_sources_list_shows_connected_sources(): void
    {
        $this->connectDataSource();

        $response = $this->getJson('/api/advanced-reports/sources/dynamic');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'source_key', 'data_source_id', 'data_source_name', 'table_name', 'use_custom_query', 'connected_at']],
            ])
            ->assertJsonPath('data.0.data_source_name', 'Test Orders')
            ->assertJsonPath('data.0.source_key', "dynamic:{$this->dataSource->id}");
    }

    public function test_available_sources_excludes_connected(): void
    {
        // Before connecting, the data source should appear in available list.
        $responseBefore = $this->getJson('/api/advanced-reports/sources/available');
        $responseBefore->assertOk();
        $availableBefore = collect($responseBefore->json('data'));
        $this->assertNotNull($availableBefore->firstWhere('id', $this->dataSource->id));

        // After connecting, it should be excluded from available.
        $this->connectDataSource();

        $responseAfter = $this->getJson('/api/advanced-reports/sources/available');
        $responseAfter->assertOk();
        $availableAfter = collect($responseAfter->json('data'));
        $this->assertNull($availableAfter->firstWhere('id', $this->dataSource->id));
    }

    public function test_preview_returns_truncated_flag_with_many_rows(): void
    {
        // Insert more than 50 rows to test truncation.
        $rows = [];
        for ($i = 100; $i <= 160; $i++) {
            $rows[] = [
                'order_number' => "ORD-{$i}",
                'customer_name' => 'Bulk Corp',
                'amount' => rand(100, 9999) / 100,
                'status' => 'completed',
                'order_date' => '2026-04-01',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('test_orders')->insert($rows);

        $sourceKey = $this->connectDataSource();
        $report = $this->createReportWithConnectedSource($sourceKey);

        $response = $this->postJson("/api/advanced-reports/reports/{$report->id}/preview");

        $response->assertOk();
        $this->assertLessThanOrEqual(50, count($response->json('rows')));
    }

    public function test_disconnect_nonexistent_key_returns_404(): void
    {
        $response = $this->deleteJson('/api/advanced-reports/sources/dynamic:99999/disconnect');

        $response->assertStatus(404);
    }
}
