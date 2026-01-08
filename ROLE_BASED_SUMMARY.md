# Role-Based System - Complete Summary

## ✅ What's Been Implemented

### 1. Database Structure
- **roles** table - stores all available roles
- **permissions** table - stores all permissions (resource + action)
- **role_permissions** - junction table linking roles to permissions
- **role_hierarchy** - defines parent-child role relationships
- **users.role_id** - foreign key to roles table (replaces hardcoded ENUM)

### 2. Dynamic Role Management UI
- Create, edit, delete roles through frontend dashboard
- Assign permissions to roles with grouped UI
- Define role hierarchies (parent-child relationships)
- Create custom permissions for new resources

### 3. Simplified User Management
- **All users** stored in `users` table only
- No more separate tables for sales_managers, sales_persons, employees
- Admin assigns roles from dropdown (loaded dynamically from roles table)
- Optional client assignment for any role

### 4. User Flow

#### Registration:
```
User registers → users table → role_id = 'client' role → is_approved = 0
```

#### Approval:
```
Admin → selects role from dropdown → optionally assigns client → approves
User → can now log in with assigned role and permissions
```

## 🎯 Key Benefits

1. **No Code Changes for New Roles** - Admin creates roles through UI
2. **Flexible Permissions** - Assign any combination of permissions to roles
3. **Hierarchical Roles** - Roles can inherit permissions from parent roles
4. **Simpler Architecture** - One user table, not multiple user type tables
5. **Dynamic & Scalable** - Easy to add new resources and permissions

## 📋 How to Use

### Step 1: Run Migrations
```bash
cd backend
php migrations/run_migrations.php
```

This creates:
- All role management tables
- Default roles (admin, sales_manager, sales_person, employee, client)
- Default permissions for existing resources
- Assigns permissions to default roles

### Step 2: Access Role Management
1. Log in as admin
2. Navigate to **Role Management** in dashboard
3. View/edit roles and permissions

### Step 3: Approve Users
1. Go to **Admin Panel**
2. View pending users
3. Click **Approve** on a user
4. Select a role from the dropdown (loaded from roles table)
5. Optionally assign a client
6. Click **Approve User**

## 🔧 How Different Roles Work

### Admin
- Role: `admin` from roles table
- Client: None (or any)
- Permissions: ALL
- Can: Everything including role management

### Regular Employee  
- Role: `employee` from roles table
- Client: None (or specific one)
- Permissions: Read enquiries, read clients
- Can: View data, no editing

### Client Account Manager
- Role: Custom role you create (e.g., "account_manager")
- Client: Assigned to specific client (e.g., "ABC Corp")
- Permissions: You decide (enquiries.read, enquiries.create, clients.read)
- Can: Manage enquiries for their assigned client

### Sales Person
- Role: `sales_person` from roles table
- Client: Assigned to specific client they manage
- Permissions: enquiries CRUD, clients.read, sales.read
- Can: Manage enquiries and view analytics for their client

## 📝 Creating Custom Roles

Example: Creating a "Viewer" role

1. Go to **Role Management** → **Roles** tab
2. Click **Create New Role**
3. Fill in:
   - Name: `viewer`
   - Description: `Read-only access to all data`
4. Select permissions:
   - ✅ enquiries.read
   - ✅ clients.read
   - ✅ analytics.read
5. Optionally set parent roles (to inherit permissions)
6. Click **Save Role**

Now when approving users, "Viewer" appears in the dropdown!

## 🔐 Permission System

### Permission Format
```
resource.action
```

Examples:
- `enquiries.create` - Can create enquiries
- `enquiries.read` - Can view enquiries
- `users.update` - Can edit users
- `roles.create` - Can create new roles

### Resources
- `enquiries` - Enquiry management
- `users` - User management
- `roles` - Role management
- `permissions` - Permission management
- `clients` - Client management
- `sales` - Sales data
- `analytics` - Analytics/reports
- `admin` - Admin functions (key rotation, etc.)

## 🎨 UI Features

### Role Editor Modal
- Clean, organized layout
- Permissions grouped by resource
- Checkboxes show descriptions
- Hierarchy section for parent roles
- Multi-select for parent roles (hold Ctrl/Cmd)

### User Approval Modal
- Role dropdown (dynamically loaded)
- Optional client dropdown (for all roles)
- Shows role descriptions
- Clear validation messages

## 🚀 API Endpoints

### Roles
- `GET /api/roles` - List all roles
- `GET /api/roles/{id}` - Get role details
- `POST /api/roles` - Create role
- `PUT /api/roles/{id}` - Update role
- `DELETE /api/roles/{id}` - Delete role

### Permissions
- `GET /api/permissions` - List all permissions
- `POST /api/permissions` - Create permission
- `PUT /api/permissions/{id}` - Update permission
- `DELETE /api/permissions/{id}` - Delete permission

### User Approval
- `POST /api/auth/approve`
  ```json
  {
    "user_id": 123,
    "role_id": 3,
    "client_id": 10  // optional
  }
  ```

## ✨ Example Scenarios

### Scenario 1: Onboarding New Employee
1. New employee registers
2. Admin approves with role: "employee"
3. No client assigned (can see all data)
4. Employee logs in → read-only access to enquiries

### Scenario 2: Client Contact Person
1. Client rep registers
2. Admin approves with role: "client"
3. Client assigned: "XYZ Company"
4. User logs in → can only see XYZ Company's enquiries

### Scenario 3: Custom Department Head
1. Admin creates role: "dept_head"
2. Assigns permissions: all enquiries, users.read, analytics.read
3. New user registers
4. Admin approves with role: "dept_head"
5. User has custom access level

## 🔄 Migration from Old System

### What Happens to Existing Users?
- Migration automatically sets `role_id` based on old `role` column
- Old `role` column kept for backward compatibility
- `sales_manager_id`, `sales_person_id`, `employee_id` columns remain but unused

### Do I Need to Change Existing Code?
- **Backend**: Updated to use `role_id`
- **Frontend**: Updated to load roles dynamically
- **Old code**: Still works reading `role` column

## ⚠️ Important Notes

1. **Admin-Only Access** - Role management requires admin role
2. **Default Roles Protected** - Cannot delete default system roles
3. **Circular References** - System prevents circular role hierarchies
4. **All Users Need Approval** - Even non-client roles need admin approval
5. **Client Assignment Optional** - Any role can have a client assigned

## 📚 Files Changed

### Backend
- `backend/migrations/020_create_role_management_tables.sql`
- `backend/migrations/021_simplify_users_to_role_based.sql`
- `backend/src/Repositories/RoleRepository.php` (new)
- `backend/src/Repositories/PermissionRepository.php` (new)
- `backend/src/Repositories/UserRepository.php` (updated)
- `backend/src/Services/RoleService.php` (new)
- `backend/src/Services/AuthService.php` (updated)
- `backend/src/Controllers/RoleController.php` (new)
- `backend/src/Controllers/AuthController.php` (updated)
- `backend/src/Routes.php` (updated)
- `backend/src/App.php` (updated)

### Frontend
- `frontend-dashboard/roles.html` (new)
- `frontend-dashboard/js/roles.js` (new)
- `frontend-dashboard/js/admin.js` (updated)
- `frontend-dashboard/js/api.js` (updated)
- All dashboard navigation menus (updated)

## 🎯 Next Steps

1. **Run the migrations**
2. **Test role management UI**
3. **Create custom roles for your needs**
4. **Approve pending users with new system**
5. **Remove old sales_managers/sales_persons tables** (optional, after verification)

## ❓ Questions?

See `ROLE_BASED_MIGRATION_GUIDE.md` for detailed technical information and troubleshooting.

