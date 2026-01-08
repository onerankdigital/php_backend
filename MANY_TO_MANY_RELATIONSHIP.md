# Many-to-Many User-Client Relationship

## ✅ Correct Architecture

### The Problem with Previous Design:
```
users.client_id → clients.id
```
**Issue:** Each user could only manage ONE client ❌

### New Design (Correct):
```
user_clients (junction table)
├── user_id → users.id
└── client_id → clients.id
```
**Result:** 
- ✅ One user can manage multiple clients
- ✅ One client can have multiple users

## 📊 Database Structure

### Junction Table:
```sql
CREATE TABLE user_clients (
    user_id BIGINT,
    client_id BIGINT,
    created_at TIMESTAMP,
    PRIMARY KEY (user_id, client_id)
);
```

### Example Data:
```
user_clients table:
user_id | client_id | created_at
--------|-----------|------------
  42    |    100    | 2024-01-15  ← John manages ABC Corp
  42    |    101    | 2024-01-20  ← John also manages XYZ Inc
  43    |    100    | 2024-01-18  ← Jane also manages ABC Corp
  44    |    102    | 2024-01-22  ← Bob manages DEF Ltd
```

**Result:**
- User 42 (John) manages 2 clients: ABC Corp + XYZ Inc
- User 43 (Jane) manages 1 client: ABC Corp
- Client 100 (ABC Corp) has 2 users: John + Jane

## 🎯 Use Cases

### Use Case 1: Sales Person Manages Multiple Clients
```
John (sales_person)
├── ABC Corp
├── XYZ Inc
└── DEF Ltd

When John logs in → sees enquiries from all 3 clients
```

### Use Case 2: Team Collaborating on Same Client
```
ABC Corp (client)
├── John (sales_manager)
├── Jane (sales_person)
└── Bob (sales_person)

All three can manage ABC Corp's enquiries
```

### Use Case 3: Adding Client to User's Portfolio
```
1. John creates "New Corp" → auto-assigned via junction table
2. Later, admin adds Jane to "New Corp" → insert into junction table
3. Now both John and Jane manage "New Corp"
```

## 🔧 How It Works

### Creating a Client (Auto-Assignment):
```php
// 1. Create client
$clientId = $clientRepository->create($data);

// 2. Assign creator to client (if not admin/employee)
$userClientRepository->assignUserToClient($userId, $clientId);

// Result: INSERT INTO user_clients (user_id, client_id) VALUES (42, 123)
```

### Getting Clients for a User:
```php
// Get all client IDs for user
$clientIds = $userClientRepository->getClientIdsByUserId(42);
// Returns: [100, 101] (John's clients)

// Then get client details
$clients = $clientRepository->getAll($limit, $offset, $clientIds);
```

### Getting Users for a Client:
```php
// Get all users assigned to client
$users = $userClientRepository->getUsersByClientId(100);
// Returns:
// [
//   {id: 42, email: "john@...", role: "sales_manager"},
//   {id: 43, email: "jane@...", role: "sales_person"}
// ]
```

## 📝 API Examples

### When User Creates Client:
```json
POST /api/clients
{
  "client_name": "New Corp",
  "package": "Premium"
}

Response:
{
  "success": true,
  "message": "Client created and assigned to you",
  "data": {
    "id": 123,
    "client_name": "New Corp",
    "assigned_users": [
      {
        "id": 42,
        "email": "john@example.com",
        "role_name": "sales_person"
      }
    ],
    "assigned_users_count": 1
  }
}
```

### Admin Assigns Additional User:
```json
POST /api/users/43/clients
{
  "client_id": 123
}

Result: Jane is now also assigned to "New Corp"
```

## 🎨 Frontend Display

### Client List for User:
```javascript
// User sees only their assigned clients
GET /api/clients

Response:
{
  "data": [
    {
      "id": 100,
      "client_name": "ABC Corp",
      "assigned_users_count": 2
    },
    {
      "id": 101,
      "client_name": "XYZ Inc",
      "assigned_users_count": 1
    }
  ]
}
```

### Client Details:
```javascript
GET /api/clients/100

Response:
{
  "id": 100,
  "client_name": "ABC Corp",
  "assigned_users": [
    {
      "id": 42,
      "email": "john@example.com",
      "role_name": "sales_manager",
      "assigned_at": "2024-01-15"
    },
    {
      "id": 43,
      "email": "jane@example.com",
      "role_name": "sales_person",
      "assigned_at": "2024-01-18"
    }
  ],
  "assigned_users_count": 2
}
```

## 🔐 Access Control

### Checking Access:
```php
// Does user 42 have access to client 100?
$hasAccess = $userClientRepository->hasAccess(42, 100);
// Returns: true (John is assigned to ABC Corp)

$hasAccess = $userClientRepository->hasAccess(44, 100);
// Returns: false (Bob is NOT assigned to ABC Corp)
```

### Role-Based Access:
- **Admin/Employee**: See ALL clients (bypass junction table)
- **Other roles**: See only clients in junction table

## 📊 Migration Impact

### Before (Single client per user):
```sql
users table:
id | email         | client_id
42 | john@...      | 100      ← John can only manage 1 client
```

### After (Multiple clients per user):
```sql
users table:
id | email         | client_id
42 | john@...      | 100      ← Kept for backward compatibility

user_clients table:
user_id | client_id
42      | 100      ← John's main client
42      | 101      ← John's second client
42      | 102      ← John's third client
```

## 🚀 Benefits

1. **Flexibility**: Users can manage multiple clients
2. **Collaboration**: Multiple users can work on same client
3. **Scalability**: Easy to add/remove client assignments
4. **History**: Track when users were assigned
5. **Clean**: No duplicate data, proper normalization

## ✅ Summary

**Old Way (Wrong):**
```
users.client_id = 100
```
→ User can only manage ONE client

**New Way (Correct):**
```
user_clients:
  (user: 42, client: 100)
  (user: 42, client: 101)
  (user: 42, client: 102)
```
→ User can manage MULTIPLE clients

This is the standard many-to-many relationship pattern!

