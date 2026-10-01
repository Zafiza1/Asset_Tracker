<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesProject;
use App\Models\CustomField;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomFieldController extends Controller
{
    use ResolvesProject;
    private function rules(?CustomField $field = null): array { return ['entity_type'=>['sometimes','string','max:100'],'key'=>['required','string','max:100','regex:/^[a-z][a-z0-9_]*$/', Rule::unique('custom_fields','key')->where('project_id',$field?->project_id ?? $this->resolveProject()->id)->where('entity_type',$field?->entity_type ?? request('entity_type','asset'))->ignore($field?->id)],'label'=>['required','string','max:255'],'type'=>['required',Rule::in(CustomField::TYPES)],'required'=>['boolean'],'default_value'=>['nullable'],'options'=>['nullable','array'],'validation'=>['nullable','array'],'visibility'=>['nullable',Rule::in(['visible','hidden','readonly'])],'sort_order'=>['nullable','integer','min:0'],'active'=>['nullable','boolean']]; }
    public function index(Request $request) { $project=$this->resolveProject(); $this->authorize('viewAny',[CustomField::class,$project]); return response()->json(['success'=>true,'data'=>CustomField::where('entity_type',$request->query('entity_type','asset'))->orderBy('sort_order')->get()]); }
    public function store(Request $request) { $project=$this->resolveProject(); $this->authorize('manage',[CustomField::class,$project]); $field=CustomField::create(array_merge($request->validate($this->rules()),['organization_id'=>$project->organization_id,'project_id'=>$project->id])); return response()->json(['success'=>true,'data'=>$field],201); }
    public function update(Request $request, CustomField $customField) { $project=$this->resolveProject(); $this->authorize('manage',[CustomField::class,$project]); $customField->update($request->validate($this->rules($customField))); return response()->json(['success'=>true,'data'=>$customField->fresh()]); }
    public function destroy(CustomField $customField) { $project=$this->resolveProject(); $this->authorize('manage',[CustomField::class,$project]); $customField->delete(); return response()->json(['success'=>true]); }
}
