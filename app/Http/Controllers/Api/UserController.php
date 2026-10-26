<?php

namespace App\Http\Controllers\Api;

use App\Core\CRUD\CRUDController;
use App\Core\CRUD\Field;
use App\Core\CRUD\InputMaker;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends CRUDController
{
    protected string $model = User::class;

    protected array $searchable = ['name', 'email', 'phone'];

    protected string $defaultSortBy = 'id';

    protected string $defaultSortOrder = 'desc';

    protected function inputMaker(): InputMaker
    {
        return InputMaker::make()
            ->title('users.title')
            ->singularTitle('header.member')
            ->model(User::class)
            ->fields([
                Field::text('name', 'common.name')->required()->col(6),
                Field::email('email', 'auth.email')->required()->rules('email')->col(6),
                Field::tel('phone', 'users.phoneCol')->col(6),
                Field::select('status', 'common.status', [
                    ['value' => 'active', 'label' => 'common.active'],
                    ['value' => 'inactive', 'label' => 'common.inactive'],
                ])->default('active')->required()->col(6),
                Field::password('password', 'auth.password')
                    ->col(12)
                    ->help('اتركه فارغاً عند التعديل للإبقاء على كلمة المرور الحالية.'),
            ]);
    }

    public function index(Request $request): JsonResponse
    {
        $response = parent::index($request);
        $payload = $response->getData(true);

        $payload['stats'] = [
            'total' => User::count(),
            'active' => User::where('status', 'active')->count(),
            'inactive' => User::where('status', 'inactive')->count(),
        ];

        $payload['data'] = collect($payload['data'])->map(function (array $user) {
            $user['avatar_url'] = 'https://ui-avatars.com/api/?background=00C896&color=fff&name='.urlencode($user['name']);

            return $user;
        })->all();

        return response()->json($payload);
    }

    public function store(Request $request): JsonResponse
    {
        return parent::store($request);
    }

    public function update(Request $request, int|string $id): JsonResponse
    {
        return parent::update($request, $id);
    }

    public function destroy(int|string|Request $ids): JsonResponse
    {
        $idArray = is_string($ids) ? explode(',', $ids) : (is_array($ids) ? $ids : [$ids]);

        if (in_array((string) auth()->id(), array_map('strval', $idArray), true)) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكنك حذف حسابك الحالي.',
            ], 422);
        }

        return parent::destroy($ids);
    }

    protected function beforeSave(Model $model, Request $request, bool $isUpdate): void
    {
        /** @var User $model */
        if ($request->filled('password')) {
            $model->password = Hash::make($request->password);
        } elseif ($isUpdate) {
            // Keep the existing password when the field is left empty.
            $model->password = $model->getOriginal('password');
        } else {
            $model->password = Hash::make('12345678');
        }

        $model->email = strtolower(trim((string) $request->email));
    }

    protected function customValidationRules(bool $isUpdate = false, mixed $currentId = null): array
    {
        $uniqueEmail = 'unique:users,email';

        if ($isUpdate && $currentId) {
            $uniqueEmail .= ",{$currentId}";
        }

        return [
            'email' => ['required', 'email', $uniqueEmail],
        ];
    }
}
