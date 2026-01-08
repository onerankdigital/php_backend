# Simplified Client Architecture

## ✅ What Changed

### Before (Complex):
```
clients table:
- sales_manager_id → sales_managers.id
- sales_person_id → sales_persons.id

users table:
- sales_manager_id → sales_managers.id
- sales_person_id → sales_persons.id
- employee_id → employees.id
```

**Problems:**
- Multiple tables to maintain
- Complex join queries
- Hard to add new user types
- Filtering logic spread across multiple tables

### After (Simple):
```
clients table:
- (no sales columns)

users table:
- role_id → roles.id (what role they have)
- client_id → clients.id (which client they manage)
```

**Benefits:**
- ✅ Single relationship: user → client
- ✅ No complex joins needed
- ✅ Easy to add new roles
- ✅ Clear access control logic

## 🔄 New Access Logic

### User Access to Clients

**Admin/Employee:**
- Can see ALL clients
- `users.role_id` = admin or employee role

**Other Roles (sales_manager, sales_person, client, custom):**
- Can see only assigned client
- Filter by `users.client_id`

### Example Queries

**Get clients for a user:**
```sql
-- Admin/Employee: all clients
SELECT * FROM clients;

-- Other roles: only assigned client
SELECT c.* 
FROM clients c
INNER JOIN users u ON c.id = u.client_id
WHERE u.id = ? AND u.is_approved = 1;
```

**Get users for a client:**
```sql
SELECT u.id, u.email, r.name as role_name
FROM users u
LEFT JOIN roles r ON u.role_id = r.id
WHERE u.client_id = ? AND u.is_approved = 1;
```

## 📊 Migration Impact

### Migration 023 Changes:
```sql
-- Drops from clients table:
DROP COLUMN sales_manager_id;
DROP COLUMN sales_person_id;
```

### What Stays:
```sql
-- users table keeps:
client_id -- Points to which client this user manages
role_id   -- Points to what role this user has
```

## 🎯 How It Works Now

### Scenario 1: Assigning User to Client

**Admin approves user:**
1. Selects role (e.g., "sales_person")
2. Selects client (e.g., "ABC Corp")
3. System sets:
   - `users.role_id` = sales_person role ID
   - `users.client_id` = ABC Corp ID

**Result:**
- User can access ABC Corp's data
- User has sales_person permissions

### Scenario 2: Multiple Users per Client

**ABC Corp has:**
- User 1: role = sales_manager, client_id = ABC Corp
- User 2: role = sales_person, client_id = ABC Corp
- User 3: role = client, client_id = ABC Corp

**All 3 users:**
- Access ABC Corp's enquiries
- See ABC Corp's packages
- Have different permissions based on role

### Scenario 3: User Without Client

**Employee user:**
- role_id = employee role
- client_id = NULL

**Result:**
- Can see all clients (based on employee role)
- Not tied to specific client

## 🔧 Code Changes Summary

### ClientRepository
**Added:**
- `getClientIdsByUserId()` - Get client IDs for a user
- `getUsersByClientId()` - Get users assigned to a client

**Removed:**
- References to sales_manager_id/sales_person_id

### ClientService
**Simplified:**
- `getClientIdsForUser()` - Simple logic based on role
- No more complex sales person/manager filtering
- No dependencies on SalesManagerRepository/SalesPersonRepository

**Constructor:**
```php
// Before:
public function __construct(
    ClientRepository $repository,
    Crypto $crypto,
    ?SalesManagerRepository $salesManagerRepository = null,
    ?SalesPersonRepository $salesPersonRepository = null
)

// After:
public function __construct(
    ClientRepository $repository,
    Crypto $crypto
)
```

### App.php
**Changed:**
```php
// Before:
$clientService = new ClientService(
    $clientRepository, 
    $this->crypto, 
    $salesManagerRepository, 
    $salesPersonRepository
);

// After:
$clientService = new ClientService(
    $clientRepository, 
    $this->crypto
);
```

### Routes.php
**Simplified $_SERVER variables:**
```php
// Before:
$_SERVER['AUTH_USER_SALES_MANAGER_ID'] = ...;
$_SERVER['AUTH_USER_SALES_PERSON_ID'] = ...;
$_SERVER['AUTH_USER_EMPLOYEE_ID'] = ...;

// After:
$_SERVER['AUTH_USER_ROLE_ID'] = ...;
// (sales columns removed)
```

## 📱 Frontend Impact

### Client List View
**Before:**
```javascript
// Filtered by sales_person_id parameter
api.getAllClients(limit, offset, filter, salesPersonId);
```

**After:**
```javascript
// Automatically filtered by user's client_id
api.getAllClients(limit, offset, filter);
```

### Client Details
**New field in response:**
```json
{
  "id": 123,
  "client_name": "ABC Corp",
  "assigned_users": [
    {
      "id": 45,
      "email": "john@example.com",
      "role_name": "sales_person"
    },
    {
      "id": 67,
      "email": "jane@example.com",
      "role_name": "sales_manager"
    }
  ],
  "assigned_users_count": 2
}
```

## ✅ Testing Checklist

After running migration 023:

- [ ] Admin can see all clients
- [ ] Employee can see all clients
- [ ] Sales person sees only their assigned client
- [ ] Client user sees only their assigned client
- [ ] Client list shows assigned users
- [ ] User approval assigns client correctly
- [ ] Enquiries filtered by client work
- [ ] Analytics filtered by client work

## 🚀 Next Steps

1. **Run migration 023:**
   ```bash
   cd backend
   php migrations/run_migrations.php
   ```

2. **Test the new system**
3. **Eventually remove old repositories** (SalesManagerRepository, SalesPersonRepository)
4. **Update AnalyticsService** to use new client filtering

## 💡 Key Takeaway

**One Simple Relationship:**
```
USER → has role_id (what they can do)
USER → has client_id (what they can access)
```

No more intermediate tables, no more complex joins, no more confusion!

