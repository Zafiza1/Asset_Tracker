<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesProject;
use App\Http\Requests\ConfigureModuleRequest;
use App\Http\Requests\InstallModuleRequest;
use App\Http\Requests\UpgradeModuleRequest;
use App\Http\Resources\ProjectModuleResource;
use App\Models\ProjectModule;
use App\Services\ModuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module lifecycle for the current project (X-Project-Id). Modules are
 * addressed by slug; every state change goes through ModuleService.
 */
class ProjectModuleController extends Controller
{
    use ResolvesProject;

    public function __construct(protected ModuleService $modules)
    {
    }

    public function index(Request $request)
    {
        $project = $this->resolveProject();
        $this->authorize('viewAny', [ProjectModule::class, $project]);

        $query = ProjectModule::query()
            ->with(['module.versions', 'moduleVersion'])
            ->where('project_id', $project->id);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        } else {
            $query->active();
        }

        $perPage = $this->perPage($request);
        $projectModules = $query->orderBy('installed_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => ProjectModuleResource::collection($projectModules->items()),
            'meta' => [
                'current_page' => $projectModules->currentPage(),
                'per_page' => $projectModules->perPage(),
                'total' => $projectModules->total(),
                'last_page' => $projectModules->lastPage(),
            ],
        ]);
    }

    public function store(InstallModuleRequest $request)
    {
        $project = $this->resolveProject();
        $this->authorize('install', [ProjectModule::class, $project]);

        $projectModule = $this->modules->install(
            $project,
            $request->validated('module'),
            $request->validated('version'),
            $request->validated('configuration') ?? [],
            $request->user(),
        );

        return $this->respond($projectModule, 'Module installed successfully', 201);
    }

    public function show(string $module)
    {
        $project = $this->resolveProject();
        $this->authorize('viewAny', [ProjectModule::class, $project]);

        return $this->respond($this->modules->findForProject($project, $module));
    }

    public function configure(ConfigureModuleRequest $request, string $module)
    {
        $project = $this->resolveProject();
        $this->authorize('configure', [ProjectModule::class, $project]);

        $projectModule = $this->modules->configure(
            $this->modules->findForProject($project, $module),
            $request->validated('configuration'),
            $request->user(),
        );

        return $this->respond($projectModule, 'Module configured successfully');
    }

    public function enable(Request $request, string $module)
    {
        $project = $this->resolveProject();
        $this->authorize('enable', [ProjectModule::class, $project]);

        $projectModule = $this->modules->enable($this->modules->findForProject($project, $module), $request->user());

        return $this->respond($projectModule, 'Module enabled successfully');
    }

    public function disable(Request $request, string $module)
    {
        $project = $this->resolveProject();
        $this->authorize('disable', [ProjectModule::class, $project]);

        $projectModule = $this->modules->disable($this->modules->findForProject($project, $module), $request->user());

        return $this->respond($projectModule, 'Module disabled successfully');
    }

    public function upgrade(UpgradeModuleRequest $request, string $module)
    {
        $project = $this->resolveProject();
        $this->authorize('install', [ProjectModule::class, $project]);

        $projectModule = $this->modules->upgrade(
            $this->modules->findForProject($project, $module),
            $request->validated('version'),
            $request->validated('configuration') ?? [],
            $request->user(),
        );

        return $this->respond($projectModule, 'Module upgraded successfully');
    }

    public function destroy(Request $request, string $module)
    {
        $project = $this->resolveProject();
        $this->authorize('uninstall', [ProjectModule::class, $project]);

        $this->modules->uninstall($this->modules->findForProject($project, $module), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Module uninstalled successfully',
        ]);
    }

    protected function respond(ProjectModule $projectModule, ?string $message = null, int $status = 200): JsonResponse
    {
        $projectModule->loadMissing(['module.versions', 'moduleVersion']);

        $body = [
            'success' => true,
            'data' => new ProjectModuleResource($projectModule),
        ];

        if ($message) {
            $body['message'] = $message;
        }

        return response()->json($body, $status);
    }
}
