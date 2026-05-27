<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEquipmentRequest;
use App\Http\Requests\UpdateEquipmentRequest;
use App\Http\Resources\EquipmentResource;
use App\Models\Department;
use App\Models\Equipment;
use App\Models\EquipmentCode;
use App\Models\FlexMessageTemplate;
use App\Services\EquipmentIdGenerator;
use App\Services\MophAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class EquipmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $hospitalId = $request->user()->hospital_id;

        $query = Equipment::with([
            'department:id,code,name_th',
            'equipmentCode:id,code,name_th,name_en',
            'location:id,name',
            'responsibleUser:id,name,full_name',
            'latestCalibration',
        ])->where('hospital_id', $hospitalId);

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('fiscal_year')) {
            $query->where('fiscal_year', $request->integer('fiscal_year'));
        }
        if ($request->filled('equipment_code_id')) {
            $query->where('equipment_code_id', $request->integer('equipment_code_id'));
        }
        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(function ($q) use ($term) {
                $q->where('id_code', 'like', $term)
                  ->orWhere('name_th', 'like', $term)
                  ->orWhere('manufacturer', 'like', $term)
                  ->orWhere('model', 'like', $term)
                  ->orWhere('serial_number', 'like', $term)
                  ->orWhere('asset_number', 'like', $term);
            });
        }

        $sort = $request->string('sort', 'id_code')->value();
        $dir = $request->string('dir', 'asc')->value() === 'desc' ? 'desc' : 'asc';
        if (in_array($sort, ['id_code', 'name_th', 'manufacturer', 'model', 'fiscal_year', 'status', 'created_at'])) {
            $query->orderBy($sort, $dir);
        }

        $perPage = min($request->integer('per_page', 25), 100);

        return EquipmentResource::collection($query->paginate($perPage));
    }

    public function show(Equipment $equipment, Request $request): EquipmentResource
    {
        abort_if($equipment->hospital_id !== $request->user()->hospital_id, 404);

        $equipment->load([
            'department', 'equipmentCode.riskLevel', 'location',
            'responsibleUser:id,name,full_name,email',
            'latestCalibration',
            'calibrations' => fn ($q) => $q->latest('calibrated_at')->limit(5),
        ]);

        return new EquipmentResource($equipment);
    }

    public function store(StoreEquipmentRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $data['hospital_id'] = $user->hospital_id;
        $data['created_by'] = $user->id;
        $data['updated_by'] = $user->id;
        $data['status'] = $data['status'] ?? 'ACTIVE';

        // Auto-generate id_code if not supplied
        if (empty($data['id_code'])) {
            $dept = Department::findOrFail($data['department_id']);
            $code = EquipmentCode::findOrFail($data['equipment_code_id']);
            $data['id_code'] = (new EquipmentIdGenerator($user->hospital))->generate($dept, $code);
        }

        $equipment = Equipment::create($data);
        $equipment->load(['department', 'equipmentCode', 'location', 'responsibleUser']);

        // Fire MOPH alert (silently fails if disabled)
        app(MophAlertService::class)->notify(
            $user->hospital,
            FlexMessageTemplate::KEY_CREATE_EQUIPMENT,
            app(MophAlertService::class)->buildEquipmentVariables($equipment),
            'equipment.created:'.$equipment->id,
        );

        return response()->json(new EquipmentResource($equipment), 201);
    }

    public function update(UpdateEquipmentRequest $request, Equipment $equipment): EquipmentResource
    {
        abort_if($equipment->hospital_id !== $request->user()->hospital_id, 404);

        $data = $request->validated();
        $data['updated_by'] = $request->user()->id;
        $equipment->update($data);
        $equipment->load(['department', 'equipmentCode', 'location', 'responsibleUser']);

        return new EquipmentResource($equipment);
    }

    public function destroy(Equipment $equipment, Request $request): JsonResponse
    {
        abort_if($equipment->hospital_id !== $request->user()->hospital_id, 404);
        abort_unless($request->user()->hasRole('admin'), 403, 'เฉพาะ admin เท่านั้นที่สามารถลบเครื่องมือได้');

        $equipment->delete();

        return response()->json(['message' => 'ลบเครื่องมือเรียบร้อย']);
    }

    public function uploadImage(Request $request, Equipment $equipment): JsonResponse
    {
        abort_if($equipment->hospital_id !== $request->user()->hospital_id, 404);
        abort_unless($request->user()->hasAnyRole(['admin', 'staff']), 403);

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
        ]);

        // Delete old image if exists
        if ($equipment->image_path) {
            Storage::disk('public')->delete($equipment->image_path);
        }

        $path = $request->file('image')->store('equipment-images', 'public');
        $equipment->update(['image_path' => $path]);

        return response()->json([
            'image_url' => $equipment->fresh()->image_url,
            'message' => 'อัปโหลดรูปภาพเรียบร้อย',
        ]);
    }

    public function removeImage(Request $request, Equipment $equipment): JsonResponse
    {
        abort_if($equipment->hospital_id !== $request->user()->hospital_id, 404);
        abort_unless($request->user()->hasAnyRole(['admin', 'staff']), 403);

        if ($equipment->image_path) {
            Storage::disk('public')->delete($equipment->image_path);
            $equipment->update(['image_path' => null]);
        }

        return response()->json(['message' => 'ลบรูปภาพเรียบร้อย']);
    }

    public function previewIdCode(Request $request): JsonResponse
    {
        $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'equipment_code_id' => ['required', 'integer', 'exists:equipment_codes,id'],
        ]);

        $user = $request->user();
        $dept = Department::findOrFail($request->integer('department_id'));
        $code = EquipmentCode::findOrFail($request->integer('equipment_code_id'));

        $idCode = (new EquipmentIdGenerator($user->hospital))->generate($dept, $code);

        return response()->json([
            'id_code' => $idCode,
            'department_code' => $dept->code,
            'equipment_code' => $code->code,
            'equipment_name_th' => $code->name_th,
            'equipment_name_en' => $code->name_en,
        ]);
    }
}
