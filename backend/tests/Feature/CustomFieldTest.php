<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomFieldTest extends TestCase
{
    use RefreshDatabase;
    private Project $project;
    private array $headers;
    protected function setUp(): void { parent::setUp(); $this->seed(\Database\Seeders\RoleAndPermissionSeeder::class); $this->project=Project::factory()->create(); $user=User::factory()->create(); $user->organizations()->attach($this->project->organization_id); $user->projects()->attach($this->project->id); $user->assignRole('project-admin',$this->project->organization_id,$this->project->id); Sanctum::actingAs($user); $this->headers=['X-Organization-Id'=>$this->project->organization_id,'X-Project-Id'=>$this->project->id]; }
    public function test_definition_applies_default_and_validates_asset_metadata(): void {
        $this->postJson('/api/v1/custom-fields',['key'=>'condition','label'=>'Condition','type'=>'select','required'=>true,'default_value'=>'good','options'=>['good','repair']],$this->headers)->assertCreated();
        $this->postJson('/api/v1/assets',['name'=>'Pump','serial_number'=>'P-1'],$this->headers)->assertCreated()->assertJsonPath('data.metadata.condition','good');
        $this->postJson('/api/v1/assets',['name'=>'Pump 2','serial_number'=>'P-2','metadata'=>['condition'=>'bad']],$this->headers)->assertUnprocessable()->assertJsonValidationErrors('metadata.condition');
    }
}
