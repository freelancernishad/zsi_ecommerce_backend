<?php

namespace App\Http\Controllers\Admin\UserManagement;

use App\Models\User;
use App\Models\Address;
use Illuminate\Support\Str;
use App\Exports\UsersExport;
use App\Imports\UsersImport;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\Admin\AdminUserResource;
use App\Http\Resources\Admin\AdminUserCollection;
use App\Http\Requests\Admin\UserManagement\AdminUserUpdateRequest;
use App\Http\Requests\Admin\UserManagement\AdminUserResetPasswordRequest;
use App\Http\Requests\Admin\UserManagement\AdminUserBulkActionRequest;
use App\Http\Requests\Admin\UserManagement\AdminUserImportRequest;
use App\Utils\LogAction;
use App\Models\UserLog;

class AdminUserController extends Controller
{
    // List users with filters & pagination
public function index(Request $request)
{
    $query = User::with('addresses')->withCount('orders');

    // Global search across multiple fields
    if ($request->filled('search')) {
        $search = $request->search;
        $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%");
            // Add more fields if needed
        });
    }

    // Individual filters
    if ($request->filled('name')) {
        $query->where('name', 'like', '%' . $request->name . '%');
    }

    if ($request->filled('email')) {
        $query->where('email', 'like', '%' . $request->email . '%');
    }

    if ($request->filled('phone')) {
        $query->where('phone', 'like', '%' . $request->phone . '%');
    }

    if ($request->has('is_active')) {
        $query->where('is_active', $request->boolean('is_active'));
    }

    if ($request->has('is_blocked')) {
        $query->where('is_blocked', $request->boolean('is_blocked'));
    }

    // Sorting
    $sortBy = $request->input('sort_by', 'id'); // default sort by id
    $sortOrder = $request->input('sort_order', 'desc');
    $query->orderBy($sortBy, $sortOrder);

    // Pagination
    $perPage = $request->input('per_page', 20);
    $users = $query->paginate($perPage);


    return response()->json(['data'=>new AdminUserCollection($users)]);
   
}


    // Show single user
    public function show($id)
    {
        $user = User::findOrFail($id);
        return new AdminUserResource($user);
    }


    public function update(AdminUserUpdateRequest $request, $id)
    {
        $user = User::find($id);

        // Update fields
        $user->fill($request->only([
            'name', 'email', 'role', 'is_active', 'is_blocked', 'notes', 'phone'
        ]));

        // Capture changes for logging
        $changes = array_intersect_key($request->only(['name', 'email', 'role', 'is_active', 'is_blocked', 'phone']), $user->getDirty());
        
        $user->save();

        if (!empty($changes)) {
            LogAction::record($id, 'profile_updated', "Admin updated customer profile", ['changes' => $changes]);
        }

        return response()->json([
            'data' => new AdminUserResource($user),
            'Message' => 'User updated successfully',
            'isError' => false,
            'status_code' => 200
        ]);
    }



    // Activate / Deactivate
    public function toggleActive($id)
    {
        $user = User::findOrFail($id);
        $user->is_active = !$user->is_active;
        $user->save();

        LogAction::record($id, 'status_toggled', "Admin " . ($user->is_active ? 'activated' : 'deactivated') . " the user account");

        return response()->json([
            'message' => $user->is_active ? 'User activated' : 'User deactivated'
        ]);
    }

    // Block / Unblock
    public function toggleBlock($id)
    {
        $user = User::findOrFail($id);
        $user->is_blocked = !$user->is_blocked;
        $user->save();

        LogAction::record($id, 'block_toggled', "Admin " . ($user->is_blocked ? 'blocked' : 'unblocked') . " the user account");

        return response()->json([
            'message' => $user->is_blocked ? 'User blocked' : 'User unblocked'
        ]);
    }

    // Reset password
    public function resetPassword(AdminUserResetPasswordRequest $request, $id)
    {
        $user = User::findOrFail($id);
        $newPassword = $request->input('password', Str::random(10));
        $user->password = Hash::make($newPassword);
        $user->save();

        // Optionally send email notification

        return response()->json([
            'message' => 'Password reset successfully',
            'new_password' => $newPassword
        ]);
    }

    // Verify email manually
    public function verifyEmail($id)
    {
        $user = User::findOrFail($id);
        $user->email_verified_at = now();
        $user->save();

        return response()->json(['message' => 'Email verified']);
    }

    // Add / update admin notes
    public function updateNotes(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $user->notes = $request->input('notes', $user->notes);
        $user->save();

        return response()->json(['message' => 'Notes updated']);
    }

    // Bulk action
    public function bulkAction(AdminUserBulkActionRequest $request)
    {
        $action = $request->input('action'); // activate, deactivate, block, unblock
        $userIds = $request->input('user_ids', []);

        if (empty($userIds)) {
            return response()->json(['message' => 'No users selected'], 400);
        }

        $updateData = match ($action) {
            'activate' => ['is_active' => true],
            'deactivate' => ['is_active' => false],
            'block' => ['is_blocked' => true],
            'unblock' => ['is_blocked' => false],
            default => null,
        };

        if ($updateData) {
            User::whereIn('id', $userIds)->update($updateData);
        }

        return response()->json(['message' => 'Bulk action completed successfully']);
    }

    // Delete user (soft delete recommended)
    public function destroy($id)
    {
        $user = User::findOrFail($id);
        $user->delete(); // Soft delete if `SoftDeletes` trait is used
        return response()->json(['message' => 'User deleted']);
    }


    public function export()
    {
        return Excel::download(new UsersExport, 'users.xlsx');
    }

    public function import(AdminUserImportRequest $request)
    {
        Excel::import(new UsersImport, $request->file('file'));
        return response()->json(['message' => 'Users imported successfully']);
    }

    public function impersonate($id)
    {
        $user = User::findOrFail($id);
        
        // Generate a JWT token for the user targeting the 'user' guard
        // We use fromUser to get a token for a specific user model
        $token = JWTAuth::fromUser($user);
        
        LogAction::record($id, 'impersonation_started', "Admin started impersonating this user");
        
        return response()->json([
            'message' => 'Impersonation token generated',
            'token' => $token,
            'user' => $user
        ]);
    }

    // Store new address for user
    public function storeAddress(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'required|string|max:20',
            'street_address' => 'required|string',
            'city' => 'required|string',
            'state' => 'nullable|string',
            'zip_code' => 'nullable|string',
            'country' => 'required|string',
            'type' => 'nullable|string|in:shipping,billing,other',
            'is_default' => 'nullable|boolean'
        ]);

        // Fallback to user email if not provided for address
        if (empty($validated['email'])) {
            $validated['email'] = $user->email;
        }

        $address = $user->addresses()->create($validated);

        LogAction::record($id, 'address_created', "Admin added a new {$address->type} address", ['address' => $address]);

        return response()->json([
            'status' => 'success',
            'message' => 'Address added successfully',
            'data' => $address
        ]);
    }

    // Update a specific address
    public function updateAddress(Request $request, $id)
    {
        $address = Address::findOrFail($id);

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'required|string|max:20',
            'street_address' => 'required|string',
            'city' => 'required|string',
            'state' => 'nullable|string',
            'zip_code' => 'nullable|string',
            'country' => 'required|string',
            'type' => 'nullable|string|in:shipping,billing,other',
            'is_default' => 'nullable|boolean'
        ]);

        $address->update($validated);

        // Handle default logic if updated to default
        if (!empty($validated['is_default'])) {
            Address::where('user_id', $address->user_id)
                ->update(['is_default' => false]);
            
            LogAction::record($address->user_id, 'address_set_default', "Admin set a new default address");
        }

        LogAction::record($address->user_id, 'address_updated', "Admin updated an existing {$address->type} address", ['address' => $address]);

        return response()->json([
            'status' => 'success',
            'message' => 'Address updated successfully',
            'data' => $address
        ], 200);
    }

    // Set a specific address as default
    public function setDefaultAddress($id)
    {
        $address = Address::findOrFail($id);

        Address::where('user_id', $address->user_id)
            ->update(['is_default' => false]);

        $address->is_default = true;
        $address->save();

        LogAction::record($address->user_id, 'address_set_default', "Admin set an address as default");

        return response()->json([
            'status' => 'success',
            'message' => 'Address set as default successfully'
        ], 200);
    }

    // Delete a specific address
    public function destroyAddress($id)
    {
        $address = Address::findOrFail($id);
        $userId = $address->user_id;
        $type = $address->type;
        $address->delete();

        LogAction::record($userId, 'address_deleted', "Admin deleted a {$type} address");

        return response()->json([
            'status' => 'success',
            'message' => 'Address deleted successfully'
        ], 200);
    }

    public function getLogs($id)
    {
        $logs = UserLog::where('user_id', $id)
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'data' => $logs->items(),
            'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
            'total' => $logs->total()
        ]);
    }
}
