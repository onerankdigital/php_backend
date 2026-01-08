# Role Management System - Setup Guide

## 1. Run the Migration

First, you need to run the database migration to create the role management tables:

```bash
cd backend
php migrations/run_migrations.php
```

This will create:
- `roles` table
- `permissions` table  
- `role_permissions` junction table
- `role_hierarchy` table
- Add `role_id` column to `users` table

It will also seed default data:
- 5 default roles: admin, sales_manager, sales_person, employee, client
- 20+ default permissions for various resources
- Default permission assignments for each role
- Basic role hierarchy

## 2. Access the UI

1. Open your browser and navigate to the frontend dashboard
2. Log in as an admin user
3. Click on "Role Management" in the navigation menu

## 3. Features Available

### Roles Tab
- **View all roles** - See all roles with their permission counts
- **Create new role** - Add custom roles with name and description
- **Edit role** - Modify role details, assign permissions, set hierarchy
- **Delete role** - Remove custom roles (default roles are protected)

### Permissions Tab
- **View all permissions** - Grouped by resource (enquiries, users, clients, etc.)
- **Create new permission** - Add custom permissions with resource, action, and description
- **Edit permission** - Modify permission details
- **Delete permission** - Remove custom permissions

### Role Editor Features
- **Permission Assignment** - Select which permissions the role should have
  - Permissions are grouped by resource for easy management
  - Checkboxes show descriptions
  - Changes are saved when you click "Save Role"

- **Role Hierarchy** - Define parent-child relationships
  - Select one or more parent roles
  - Child roles inherit permissions from parents
  - Prevents circular references
  - Hold Ctrl/Cmd to select multiple parents

## 4. API Endpoints

All endpoints require admin authentication.

### Roles
- `GET /api/roles` - Get all roles
- `GET /api/roles/{id}` - Get role details with permissions and hierarchy
- `POST /api/roles` - Create new role
- `PUT /api/roles/{id}` - Update role
- `DELETE /api/roles/{id}` - Delete role
- `GET /api/roles/hierarchy` - Get full hierarchy structure

### Permissions
- `GET /api/permissions` - Get all permissions
- `GET /api/permissions/{id}` - Get permission details
- `POST /api/permissions` - Create new permission
- `PUT /api/permissions/{id}` - Update permission
- `DELETE /api/permissions/{id}` - Delete permission
- `GET /api/permissions/resources` - Get all resource types

## 5. Default Roles & Permissions

### Admin Role
- Has ALL permissions
- Top of the hierarchy
- Can manage roles, users, permissions, encryption keys

### Sales Manager Role
Permissions:
- enquiries.create, enquiries.read, enquiries.update
- users.read
- clients.create, clients.read, clients.update
- sales.read, sales.manage
- analytics.read

### Sales Person Role
Permissions:
- enquiries.create, enquiries.read, enquiries.update
- clients.read
- sales.read

### Employee Role
Permissions:
- enquiries.read
- clients.read

### Client Role
Permissions:
- enquiries.create
- enquiries.read

## 6. Troubleshooting

### Can't see roles/permissions in UI
1. Check browser console for errors
2. Verify you're logged in as admin
3. Check that the migration ran successfully
4. Verify the API endpoint is accessible (check Network tab)

### Migration errors
- If tables already exist, the migration will skip them
- Check `backend/logs/php_errors.log` for details
- Verify database credentials in `backend/config.php`

### Permission denied errors
- Only admin users can access role management
- Check your user role: `SELECT role FROM users WHERE email = 'your@email.com'`
- Make sure your user has `role = 'admin'`

## 7. Next Steps

After setup, you can:
1. Create custom roles for your organization
2. Assign specific permissions to roles
3. Set up role hierarchies to inherit permissions
4. Create new permissions for custom resources
5. Assign roles to users through the admin panel

## 8. Security Notes

- Role management is admin-only (enforced at API level)
- Default system roles (admin, client, etc.) cannot be deleted
- Circular role hierarchies are prevented
- All changes are immediate and affect user permissions instantly

