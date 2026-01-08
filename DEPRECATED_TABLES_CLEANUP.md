# Deprecated Tables Cleanup Guide

## ⚠️ Current Status

The following tables are **deprecated** but still exist:
- `sales_managers`
- `sales_persons`
- `employees`

## Why Not Remove Immediately?

There are still references to these tables in the code:
- **14 files** have sales manager/person references
- Some controllers and services still use them
- Client filtering logic depends on them
- Analytics queries reference them

## 🎯 Recommended Approach: Phased Removal

### Phase 1: Mark as Deprecated (NOW) ✅

Add comments to tables marking them as deprecated:

```sql
-- Run this now to mark tables as deprecated
ALTER TABLE sales_managers COMMENT 'DEPRECATED: Use users table with role_id instead';
ALTER TABLE sales_persons COMMENT 'DEPRECATED: Use users table with role_id instead';
ALTER TABLE employees COMMENT 'DEPRECATED: Use users table with role_id instead';
```

### Phase 2: Test the New System (1-2 weeks)

1. ✅ Verify role-based approval works
2. ✅ Test user permissions with new roles
3. ✅ Ensure analytics still works
4. ✅ Check client filtering
5. ✅ Verify enquiry access control

### Phase 3: Remove Code References (After verification)

Files that need updating:

#### Can be Safely Removed:
- `backend/src/Repositories/SalesManagerRepository.php`
- `backend/src/Repositories/SalesPersonRepository.php`
- `backend/src/Services/SalesManagerService.php`
- `backend/src/Services/SalesPersonService.php`
- `backend/src/Controllers/SalesManagerController.php`
- `backend/src/Controllers/SalesPersonController.php`

#### Need Refactoring:
- `backend/src/Services/ClientService.php` - Client filtering logic
- `backend/src/Services/AnalyticsService.php` - Analytics queries
- `backend/src/Controllers/EnquiryController.php` - Access control
- `backend/src/Routes.php` - Remove old $_SERVER variables

### Phase 4: Drop Tables (After Phase 3)

```sql
-- Only run after ALL code references are removed!
DROP TABLE IF EXISTS sales_persons;
DROP TABLE IF EXISTS sales_managers;
DROP TABLE IF EXISTS employees;
```

## 🔧 What to Do Right Now

### Option A: Keep Tables (Recommended for now)

**Pros:**
- No risk of breaking existing functionality
- Can rollback if needed
- Time to refactor gradually

**Cons:**
- Database has extra tables
- Some confusion about which system to use

### Option B: Remove Only Unused Columns

Remove columns from `users` table that are no longer needed:

```sql
-- These columns in users table are no longer used
-- But keep them for 1-2 weeks for safety
-- ALTER TABLE users DROP COLUMN sales_manager_id;
-- ALTER TABLE users DROP COLUMN sales_person_id;
-- ALTER TABLE users DROP COLUMN employee_id;

-- For now, just comment them as deprecated
ALTER TABLE users MODIFY COLUMN sales_manager_id BIGINT NULL COMMENT 'DEPRECATED: No longer used';
ALTER TABLE users MODIFY COLUMN sales_person_id BIGINT NULL COMMENT 'DEPRECATED: No longer used';
ALTER TABLE users MODIFY COLUMN employee_id BIGINT NULL COMMENT 'DEPRECATED: No longer used';
```

## 📝 Migration Script for Deprecation

Create this migration now:

```sql
-- backend/migrations/022_deprecate_old_tables.sql

-- Mark tables as deprecated (don't drop yet)
ALTER TABLE sales_managers COMMENT 'DEPRECATED: Use users table with role_id. Will be removed in future version.';
ALTER TABLE sales_persons COMMENT 'DEPRECATED: Use users table with role_id. Will be removed in future version.';
ALTER TABLE employees COMMENT 'DEPRECATED: Use users table with role_id. Will be removed in future version.';

-- Mark columns in users table as deprecated
ALTER TABLE users MODIFY COLUMN sales_manager_id BIGINT NULL COMMENT 'DEPRECATED: No longer used in role-based system';
ALTER TABLE users MODIFY COLUMN sales_person_id BIGINT NULL COMMENT 'DEPRECATED: No longer used in role-based system';
ALTER TABLE users MODIFY COLUMN employee_id BIGINT NULL COMMENT 'DEPRECATED: No longer used in role-based system';

-- Mark columns in clients table as deprecated
ALTER TABLE clients MODIFY COLUMN sales_manager_id BIGINT NULL COMMENT 'DEPRECATED: Use user->client_id relationship instead';
ALTER TABLE clients MODIFY COLUMN sales_person_id BIGINT NULL COMMENT 'DEPRECATED: Use user->client_id relationship instead';
```

## 🚀 Future Cleanup Tasks

When you're ready to remove everything (after Phase 3):

### 1. Remove Backend Files
```bash
rm backend/src/Repositories/SalesManagerRepository.php
rm backend/src/Repositories/SalesPersonRepository.php
rm backend/src/Services/SalesManagerService.php
rm backend/src/Services/SalesPersonService.php
rm backend/src/Controllers/SalesManagerController.php
rm backend/src/Controllers/SalesPersonController.php
```

### 2. Update App.php
Remove these lines:
```php
$salesManagerRepository = new \App\Repositories\SalesManagerRepository($this->db);
$salesPersonRepository = new \App\Repositories\SalesPersonRepository($this->db);
$salesPersonService = new \App\Services\SalesPersonService(...);
$salesManagerService = new \App\Services\SalesManagerService(...);
```

### 3. Update Routes.php
Remove:
```php
$_SERVER['AUTH_USER_SALES_MANAGER_ID'] = ...;
$_SERVER['AUTH_USER_SALES_PERSON_ID'] = ...;
$_SERVER['AUTH_USER_EMPLOYEE_ID'] = ...;
```

### 4. Update ClientService.php
Refactor client filtering to use only:
- `users.role_id` 
- `users.client_id`

Instead of:
- `sales_manager_id`
- `sales_person_id`

### 5. Update AnalyticsService.php
Refactor queries to use role-based filtering

### 6. Drop Tables
```sql
DROP TABLE IF EXISTS sales_persons;
DROP TABLE IF EXISTS sales_managers;
DROP TABLE IF EXISTS employees;
```

### 7. Remove Columns from Users/Clients
```sql
ALTER TABLE users DROP COLUMN sales_manager_id;
ALTER TABLE users DROP COLUMN sales_person_id;
ALTER TABLE users DROP COLUMN employee_id;

ALTER TABLE clients DROP COLUMN sales_manager_id;
ALTER TABLE clients DROP COLUMN sales_person_id;
```

## ✅ Checklist Before Full Removal

- [ ] New role system working for 2+ weeks
- [ ] No reported issues with permissions
- [ ] Analytics working correctly
- [ ] Client filtering working correctly
- [ ] All users successfully migrated to role-based system
- [ ] Backup taken before removal
- [ ] Code references removed/refactored
- [ ] Test environment cleanup verified

## 🎯 My Recommendation

**For now (today):**
1. ✅ Run the deprecation migration (022)
2. ✅ Keep using the new role-based system
3. ✅ Monitor for any issues

**In 1-2 weeks:**
1. ✅ If everything works well, start Phase 3
2. ✅ Refactor client filtering and analytics
3. ✅ Remove old code files
4. ✅ Drop tables

**Why wait?**
- Safety: Can rollback if issues found
- Testing: Real-world usage verification
- Confidence: Ensure no hidden dependencies

## 📊 What Data Will Be Lost?

If you drop the tables without migration:
- Sales manager names, emails, phones
- Sales person names, emails, phones
- Employee names, emails, phones

**But:** All user accounts are in `users` table, so no user data is lost!

## 🔄 Data Migration (If Needed)

If you want to preserve sales manager/person info before dropping tables:

```sql
-- Export to CSV for backup
SELECT * FROM sales_managers INTO OUTFILE '/tmp/sales_managers_backup.csv';
SELECT * FROM sales_persons INTO OUTFILE '/tmp/sales_persons_backup.csv';
SELECT * FROM employees INTO OUTFILE '/tmp/employees_backup.csv';
```

Or create a combined reference table:
```sql
CREATE TABLE legacy_staff_info (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('sales_manager', 'sales_person', 'employee'),
    legacy_id BIGINT,
    name VARCHAR(255),
    email VARCHAR(255),
    phone VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Copy data
INSERT INTO legacy_staff_info (type, legacy_id, name, email, phone)
SELECT 'sales_manager', id, name, email, phone FROM sales_managers;

INSERT INTO legacy_staff_info (type, legacy_id, name, email, phone)
SELECT 'sales_person', id, name, email, phone FROM sales_persons;

INSERT INTO legacy_staff_info (type, legacy_id, name, email, phone)
SELECT 'employee', id, name, email, phone FROM employees;
```

