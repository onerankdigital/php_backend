# Role-Based System Migration Guide

## Overview

The system has been refactored to use a pure role-based access control (RBAC) system. All users are now stored in the `users` table with assigned roles from the `roles` table.

## What Changed

### Database Changes
- **Users table** now uses `role_id` (foreign key to roles table) instead of hardcoded role ENUM
- **Removed need for**:
  - `sales_managers` table
  - `sales_persons` table  
  - `employees` table
  - `sales_manager_id`, `sales_person_id`, `employee_id` columns in users table

### Backend Changes
1. **UserRepository.php**
   - `create()` now accepts `roleId` instead of role string
   - `approveUser()` now accepts `roleId` and `clientId` only (removed sales_manager_id, sales_person_id, employee_id)
   - All queries join with `roles` table to get role name
   
2. **AuthService.php**
   - Simplified approval logic - all roles need approval now (no auto-approval)
   - Removed sales-specific logic
   
3. **AuthController.php**
   - Updated `approveUser()` to accept `role_id` instead of role string
   - Requires `role_id` to be provided (no longer optional)

### Frontend Changes
1. **admin.js**
   - Loads roles dynamically from the database
   - Approval modal shows all available roles from `roles` table
   - Client assignment is optional for all roles
   - No more sales manager selection

## How It Works Now

### User Registration Flow
1. User registers → creates record in `users` table with default 'client' role
2. User is marked as `is_approved = 0` (not approved)
3. Admin reviews pending users

### User Approval Flow
1. Admin selects a role from the available roles (loaded from `roles` table)
2. **Optionally** assigns a client (if this user should manage a specific client)
3. System:
   - Sets `is_approved = 1`
   - Sets `role_id` to selected role
   - Sets `client_id` if provided
   - Updates `role` column for backward compatibility

### Role Assignment Logic
- **Any role** can optionally have a client assigned
- Client assignment determines which client's data the user can access
- Role permissions determine what actions the user can perform

## Migration Steps

1. **Run the migrations**:
   ```bash
   cd backend
   php migrations/run_migrations.php
   ```

2. **Existing data**:
   - Migration 020 creates roles and assigns `role_id` to existing users
   - Migration 021 ensures all users have proper `role_id` set

3. **Test the system**:
   - Log in as admin
   - Check pending users
   - Approve a user with a role and optional client
   - Verify the user can log in and has correct permissions

## API Changes

### Approve User Endpoint
**Before**:
```json
POST /api/auth/approve
{
  "user_id": 123,
  "role": "sales_person",
  "sales_manager_id": 45
}
```

**After**:
```json
POST /api/auth/approve
{
  "user_id": 123,
  "role_id": 3,
  "client_id": 10
}
```

## Benefits

1. **Simpler architecture** - One table for all users
2. **More flexible** - Easy to add new roles through UI
3. **Better permission management** - Roles can have custom permissions
4. **Easier to maintain** - No need to sync multiple user type tables
5. **Dynamic roles** - Admins can create/modify roles without code changes

## Backward Compatibility

- The `role` column (ENUM) is kept for backward compatibility
- It's automatically updated when `role_id` changes
- Old code can still read the `role` column
- New code should use `role_id` and join with `roles` table

## Example Use Cases

### Scenario 1: Company Admin
- Role: `admin`
- Client: None
- Can do: Everything (all permissions)

### Scenario 2: Client Account Manager  
- Role: `client` (or custom role like "account_manager")
- Client: ABC Corp (ID: 10)
- Can do: View and create enquiries for ABC Corp only

### Scenario 3: Sales Person
- Role: `sales_person`
- Client: XYZ Ltd (ID: 15)
- Can do: Manage enquiries and view analytics for XYZ Ltd

### Scenario 4: Employee
- Role: `employee`
- Client: None
- Can do: Read-only access to enquiries and clients

## Troubleshooting

### Users can't log in after migration
- Check that `role_id` is set: `SELECT id, email, role, role_id FROM users;`
- Run migration 021 if needed

### Approval fails with "role_id required" error
- Update frontend to send `role_id` instead of `role`
- Check that roles are loaded in admin.js

### Permissions not working
- Verify role has permissions assigned in `role_permissions` table
- Check migration 020 ran successfully and seeded permissions

