# Radha Rani Hotel — Branch Bill Upload & Owner Portal
## Complete Project Flow, SaaS-Level UI/UX & Development Plan

**Document Type:** Full Project Specification / Development Plan  
**Project:** Radha Rani Hotel — Multi-Branch Daily Bill PDF Management Portal  
**Frontend:** HTML5, CSS3, Bootstrap 5, JavaScript and selected CSS/JS UI libraries  
**Backend:** PHP recommended for shared-hosting-friendly implementation  
**Database:** MySQL  
**Primary Data:** PDF bill files and their related metadata  
**Processing:** PDF upload, storage, listing, viewing and downloading only  
**Explicitly Out of Scope:** OCR, image processing, PDF content extraction, payment processing, accounting calculations, POS integration

---

# 1. Project Overview

The Radha Rani Hotel organization has a single owner/manager who operates multiple hotel branches.

Each branch has its own branch administrator.

At the end of each business day, the branch admin logs into the portal and uploads the branch's bill PDFs into two separate categories:

1. **Cash Bills** — bills representing payments recorded as cash.
2. **Card Bills** — bills representing payments made through customers' card transactions.

The owner has one central Owner account and can:

- Create branches.
- Edit branches.
- View branches.
- Disable/activate branches.
- Create/manage branch admin accounts.
- View uploaded bills from every branch.
- Filter bills by branch, payment type and date.
- Open/view PDF files.
- Download PDF files.
- Review upload information.
- Manage the complete system.

Branch admins are restricted to their own branch and have only the required bill-upload functionality.

The system is therefore a **multi-branch document management portal**, not a payment-processing system.

---

# 2. Core Business Rule

The most important rule is:

> Branch admins upload PDF bill documents. The portal stores and displays those PDFs. The portal does not process the payment, read the PDF, extract data from it, perform OCR, or calculate financial values from the bill.

A bill PDF is treated as a document.

The system stores metadata such as:

- Branch
- Payment type
- Business/upload date
- Uploaded file name
- Stored file name/path
- File size
- Upload timestamp
- Uploaded by
- Status
- Optional description/notes if required

---

# 3. User Roles

## 3.1 Owner

There is exactly one Owner account.

The Owner has organization-wide access.

### Owner capabilities

- Login/logout
- Dashboard
- Branch CRUD
- Branch admin CRUD
- Activate/deactivate branches
- View all branch bills
- Filter bills
- Search bills
- View PDF
- Download PDF
- View upload details
- Delete bill documents
- Manage own account
- Change password
- View activity/audit information
- Review branch-level upload status
- Review daily upload activity

The Owner can access data from all branches.

---

## 3.2 Branch Admin

Each branch has its own branch admin account.

### Branch Admin capabilities

- Login/logout
- Access own branch dashboard
- Upload Cash Bill PDF
- Upload Card Bill PDF
- View uploaded PDFs belonging to their own branch
- Open/view uploaded PDF
- Download uploaded PDF if enabled
- See upload status/history for their branch

### Branch Admin restrictions

A branch admin must NOT be able to:

- Create branches
- Edit branches
- Delete branches
- Create other admins
- Access another branch
- Access Owner pages
- Modify system settings
- Modify other users
- Access organization-wide reports
- Change payment categories
- Upload into another branch
- Access another branch's PDF files

The backend must enforce these restrictions. Hiding menu items alone is not sufficient.

---

# 4. High-Level System Architecture

```text
                    RADHA RANI HOTEL PORTAL
                              |
             +----------------+----------------+
             |                                 |
        OWNER ACCOUNT                    BRANCH ADMINS
             |                                 |
             |                         Branch-specific login
             |
       Owner Dashboard
             |
    +--------+---------+
    |        |         |
 Branches  Admins   Bills
    |        |         |
    +--------+---------+
             |
       All Branch Data
             |
       MySQL Database
             |
       PDF File Storage
```

---

# 5. Authentication Flow

## Owner Login

```text
Owner opens login page
        ↓
Enters username/email + password
        ↓
Server validates credentials
        ↓
Password verified using password hashing
        ↓
Role = OWNER
        ↓
Owner session created
        ↓
Owner Dashboard
```

## Branch Admin Login

```text
Branch Admin opens login page
        ↓
Enters credentials
        ↓
Server validates credentials
        ↓
Password verified
        ↓
System checks account status
        ↓
System identifies assigned branch
        ↓
Branch Admin session created
        ↓
Branch Dashboard
```

## Failed Login

```text
Invalid credentials
        ↓
Generic error message
        ↓
No sensitive information exposed
        ↓
Login page remains available
```

---

# 6. Session & Authorization Architecture

Every protected page must verify:

1. User is authenticated.
2. User session is valid.
3. User role is authorized.
4. Branch admin belongs to the requested branch.
5. Requested document belongs to the authorized branch when applicable.

Example:

```text
Owner → /owner/*
Branch Admin → /branch/*
Unauthenticated → /login
```

A branch admin attempting to manually access an Owner URL must receive an authorization response and must not see Owner data.

---

# 7. Owner Dashboard

The Owner Dashboard should use a professional SaaS/B2B layout.

## Dashboard Structure

### Top Header

- Radha Rani Hotel logo/name
- Page title
- Search if required
- Notifications/activity indicator if implemented
- Owner profile menu
- Logout

### Sidebar

- Dashboard
- Branches
- Branch Admins
- Bills
- Upload Activity
- Settings
- Audit Log
- Profile
- Logout

### Main Dashboard

Use compact SaaS cards.

Suggested cards:

- Total Branches
- Active Branches
- Total Branch Admins
- Today's Cash Bills
- Today's Card Bills
- Today's Total Uploaded PDFs
- Pending/No Upload Branches
- Total Stored Documents

These are document/upload counts, not monetary totals.

The system must not calculate or display payment amounts unless a future requirement explicitly adds structured financial data.

---

# 8. Branch Management

The Owner must have complete CRUD functionality.

## Branch Fields

Recommended fields:

- Branch ID
- Branch Name
- Branch Code
- Branch Address
- Contact Number
- Contact Email
- Status
- Created Date
- Updated Date

Optional:

- Branch Manager/Admin display name
- Notes

## Branch List

SaaS table:

| Column | Purpose |
|---|---|
| Branch Code | Unique identifier |
| Branch Name | Branch name |
| Admin | Assigned admin |
| Status | Active/Inactive |
| Today's Uploads | Upload count |
| Last Upload | Latest upload timestamp |
| Created | Creation date |
| Actions | View/Edit/Disable |

Actions:

- View
- Edit
- Activate
- Deactivate
- Delete where safe and permitted

Prefer deactivation/soft deletion rather than hard deletion where historical bills exist.

---

# 9. Branch Creation Flow

```text
Owner Dashboard
      ↓
Branches
      ↓
Add Branch
      ↓
Enter branch details
      ↓
Validate form
      ↓
Check unique branch code
      ↓
Create branch
      ↓
Success notification
      ↓
Branch appears in list
```

Optional combined workflow:

```text
Create Branch
     ↓
Create Branch Admin
     ↓
Assign Admin to Branch
```

Alternatively, branch and admin creation can remain separate modules.

---

# 10. Branch Admin Management

Owner can manage all branch admin accounts.

## Admin Fields

- Admin ID
- Full Name
- Username/email
- Password hash
- Branch ID
- Role
- Status
- Created At
- Last Login
- Updated At

## Admin Table

| Field | Description |
|---|---|
| Name | Admin name |
| Login ID | Email/username |
| Branch | Assigned branch |
| Status | Active/Inactive |
| Last Login | Latest successful login |
| Created | Account creation date |
| Actions | View/Edit/Reset/Disable |

Owner actions:

- Create admin
- View admin
- Edit admin
- Reset password
- Activate
- Deactivate
- Delete if appropriate
- Assign/reassign branch

Only one branch assignment should be active for a branch admin unless the business requirement later changes.

---

# 11. Branch Admin Dashboard

The Branch Admin UI should be simpler than the Owner UI.

## Sidebar

- Dashboard
- Upload Bills
- My Uploads
- Profile
- Logout

## Dashboard Cards

- Today's Cash Uploads
- Today's Card Uploads
- Today's Total Uploads
- Last Upload
- Upload Status

No organization-wide information should be displayed.

---

# 12. Bill Categories

There are exactly two document categories.

## Category 1 — Cash

The uploaded PDF represents cash-payment bills.

Database value:

```text
cash
```

## Category 2 — Card

The uploaded PDF represents card-payment bills.

Database value:

```text
card
```

Do not create a generic "payment processing" workflow.

The application only stores the uploaded documents.

---

# 13. Bill Upload Flow

## Branch Admin Upload

```text
Branch Admin Login
        ↓
Upload Bills
        ↓
Select Payment Type
        ↓
Cash / Card
        ↓
Select Business Date
        ↓
Select PDF file
        ↓
Client-side validation
        ↓
Server-side validation
        ↓
Generate secure stored filename
        ↓
Save PDF
        ↓
Insert metadata into MySQL
        ↓
Success
        ↓
Show uploaded document
```

---

# 14. Upload Form

The upload screen should contain:

### Payment Type

Two large SaaS selection cards:

```text
[ Cash Bill ]
[ Card Bill ]
```

### Business Date

Date picker.

This represents the date associated with the bill/document.

### PDF File

Drag-and-drop upload area:

```text
┌─────────────────────────────────────┐
│                                     │
│       Drag & Drop PDF Here          │
│                                     │
│       or Browse Files               │
│                                     │
│       PDF only                      │
│                                     │
└─────────────────────────────────────┘
```

### Optional Description

Short notes if required.

### Upload Button

```text
Upload Cash Bill
```

or

```text
Upload Card Bill
```

---

# 15. PDF Validation

The system must validate PDFs on both client and server.

## Recommended rules

- Allow PDF only.
- Validate MIME type.
- Validate extension.
- Validate actual file signature where practical.
- Apply maximum file-size limit.
- Reject empty files.
- Reject corrupted/invalid uploads.
- Sanitize original filename.
- Never trust the user-provided filename.

Example configuration:

```text
Allowed extension: .pdf
Recommended maximum: 20 MB per file
```

The exact maximum should be configurable.

---

# 16. Secure File Naming

Do not save files using the original filename directly.

Bad:

```text
Cash_Bill_September.pdf
```

Recommended:

```text
BR001_20260924_CASH_8f31c92a.pdf
```

or use a UUID-based filename.

Database should retain:

- Original filename
- Stored filename
- Storage path

---

# 17. PDF Storage Architecture

Recommended structure:

```text
/uploads/
    /branches/
        /BR001/
            /cash/
                2026/
                    09/
            /card/
                2026/
                    09/
        /BR002/
            /cash/
            /card/
```

PDF files should ideally not be directly executable or publicly browsable.

Access should be controlled through an authenticated PDF viewing/download endpoint.

Example:

```text
/view_bill.php?id=123
/download_bill.php?id=123
```

The endpoint must verify authorization before serving the file.

---

# 18. PDF Viewing

The Owner and authorized Branch Admin can open a PDF.

The application should display a dedicated PDF viewer page or browser PDF viewer.

Suggested layout:

```text
------------------------------------------------
← Back       Bill Document        Download
------------------------------------------------

Branch: Radha Rani - Chennai
Payment Type: Cash
Business Date: 24 Sep 2026
Uploaded By: Branch Admin
Uploaded At: 24 Sep 2026, 10:35 PM

------------------------------------------------
|                                              |
|                PDF VIEWER                    |
|                                              |
|              Document Pages                  |
|                                              |
------------------------------------------------
```

No OCR or extraction is performed.

---

# 19. Bill List — Owner

The Owner should have a central Bill Management page.

## Filters

- Branch
- Payment Type
- Business Date
- Upload Date
- Uploaded By
- Status
- Search filename

Primary filters:

```text
Branch     [All Branches]
Type       [All / Cash / Card]
Date       [Date]
```

## Table

| Column | Description |
|---|---|
| Bill ID | Internal ID |
| Branch | Branch name |
| Type | Cash/Card |
| Business Date | Bill date |
| File Name | Original filename |
| File Size | PDF size |
| Uploaded By | Admin |
| Uploaded At | Timestamp |
| Status | Available |
| Actions | View/Download/Delete |

---

# 20. Bill List — Branch Admin

Branch admins only see their own branch.

Columns:

- File name
- Payment type
- Business date
- File size
- Uploaded at
- Status
- View
- Download

They must never be able to alter the branch ID through URL parameters to access another branch.

---

# 21. Owner Branch-Wise Bill View

Owner can select a branch:

```text
Branches
   ↓
Select Branch
   ↓
Branch Overview
   ↓
Cash Bills
Card Bills
```

Branch overview can show:

- Branch name
- Admin
- Status
- Today's upload count
- Cash PDF count
- Card PDF count
- Latest upload
- Upload history

Again, these are document counts, not financial totals.

---

# 22. Daily Upload Monitoring

The Owner should be able to determine whether branches have uploaded their daily bills.

Example:

| Branch | Cash | Card | Latest Upload | Status |
|---|---:|---:|---|---|
| Branch A | 1 | 1 | 10:20 PM | Uploaded |
| Branch B | 1 | 1 | 10:31 PM | Uploaded |
| Branch C | 1 | 0 | 10:45 PM | Partial |
| Branch D | 0 | 0 | — | No Upload |

The system should clearly distinguish:

- Uploaded
- Partial
- No Upload

These statuses are based only on whether expected document uploads exist.

The exact daily requirement should be configurable if business rules differ between branches.

---

# 23. Upload History

The Owner can review historical uploads.

Filters:

- Date range
- Branch
- Payment type
- Admin
- Filename

Sorting:

- Newest first
- Oldest first

Pagination should be implemented.

Do not load thousands of records into the browser at once.

---

# 24. Search

Owner search can support:

- Branch name
- Branch code
- Original PDF filename
- Admin name

Bill search should remain metadata-based.

The system must not search inside PDF contents.

---

# 25. Delete Bill

Owner should have permission to delete a bill.

Recommended workflow:

```text
Delete
  ↓
Confirmation modal
  ↓
"Are you sure?"
  ↓
Confirm
  ↓
Delete database record
  ↓
Delete physical PDF
  ↓
Write audit record
  ↓
Success
```

For historical financial documents, a safer approach is:

```text
Soft Delete
```

instead of permanently deleting the file.

Recommended implementation:

```text
deleted_at
deleted_by
status = deleted
```

The exact retention rule should be confirmed with the hotel owner.

---

# 26. Replace/Re-upload Policy

Recommended default:

Do not silently overwrite an existing PDF.

If the same bill needs to be corrected:

```text
Upload corrected PDF
       ↓
Create new document record
       ↓
Keep audit/history
```

If duplicate prevention is required, define a business key such as:

```text
Branch + Business Date + Payment Type
```

However, this should only be enforced if the business confirms that each branch must upload exactly one PDF per payment type per day.

If multiple PDFs per day are legitimate, allow multiple files.

---

# 27. Database Design

Recommended tables:

```text
users
branches
bills
audit_logs
sessions / authentication storage if required
```

---

# 28. `users` Table

Suggested fields:

```text
id
branch_id
name
email
username
password_hash
role
status
last_login_at
created_at
updated_at
```

Roles:

```text
owner
branch_admin
```

For the Owner:

```text
branch_id = NULL
role = owner
```

For Branch Admin:

```text
branch_id = assigned branch
role = branch_admin
```

---

# 29. `branches` Table

Suggested fields:

```text
id
branch_code
branch_name
address
phone
email
status
created_at
updated_at
deleted_at
```

Recommended indexes:

```text
branch_code
status
```

---

# 30. `bills` Table

Suggested fields:

```text
id
branch_id
uploaded_by
payment_type
business_date
original_filename
stored_filename
file_path
mime_type
file_size
status
uploaded_at
updated_at
deleted_at
```

Recommended indexes:

```text
branch_id
payment_type
business_date
uploaded_by
uploaded_at
status
```

---

# 31. `audit_logs` Table

Suggested fields:

```text
id
user_id
branch_id
action
entity_type
entity_id
description
ip_address
user_agent
created_at
```

Examples:

```text
OWNER_LOGIN
BRANCH_CREATED
BRANCH_UPDATED
ADMIN_CREATED
ADMIN_DISABLED
BILL_UPLOADED
BILL_VIEWED
BILL_DOWNLOADED
BILL_DELETED
ADMIN_LOGIN
```

Audit logs are particularly useful for document accountability.

---

# 32. Relationships

```text
branches
    |
    |--- users
    |
    |--- bills

users
    |
    |--- bills uploaded_by

users
    |
    |--- audit_logs
```

Relationship rules:

```text
One Branch
   ↓
Many Branch Admins

One Branch
   ↓
Many Bills

One User
   ↓
Many Uploaded Bills

One User
   ↓
Many Audit Logs
```

---

# 33. Recommended Backend Folder Structure

```text
radha-rani-portal/
│
├── public/
│   ├── index.php
│   ├── login.php
│   ├── logout.php
│   ├── owner/
│   │   ├── dashboard.php
│   │   ├── branches.php
│   │   ├── branch-view.php
│   │   ├── admins.php
│   │   ├── bills.php
│   │   ├── upload-activity.php
│   │   ├── audit-logs.php
│   │   └── settings.php
│   │
│   ├── branch/
│   │   ├── dashboard.php
│   │   ├── upload.php
│   │   └── my-uploads.php
│   │
│   └── api/
│       ├── branches/
│       ├── admins/
│       ├── bills/
│       └── auth/
│
├── app/
│   ├── config/
│   ├── controllers/
│   ├── models/
│   ├── services/
│   ├── middleware/
│   ├── helpers/
│   └── validators/
│
├── database/
│   ├── migrations/
│   └── seeders/
│
├── storage/
│   ├── uploads/
│   ├── logs/
│   └── temp/
│
├── assets/
│   ├── css/
│   ├── js/
│   └── vendor/
│
└── README.md
```

For shared hosting, the public web root should expose only the required public files. Sensitive application/configuration/storage areas should remain outside direct web access where the hosting environment permits.

---

# 34. Frontend Technology Plan

## Core

- HTML5
- CSS3
- Bootstrap 5
- JavaScript ES6+
- Bootstrap Icons

## Optional UI libraries

Use libraries only where they improve the product:

- SweetAlert2 — confirmations and notifications
- DataTables — searchable/paginated tables where appropriate
- Flatpickr — date selection
- Select2 — advanced branch filtering
- Chart.js — dashboard upload-count charts
- PDF.js only if a custom embedded viewer is required

Do not overload the application with unnecessary libraries.

---

# 35. SaaS-Level Visual Design

The interface should look like a modern B2B SaaS product rather than a traditional hotel website.

## Design Characteristics

- Clean
- Professional
- Minimal
- High information density
- Consistent spacing
- Strong typography
- Clear hierarchy
- Compact cards
- Responsive tables
- Clear empty states
- Accessible forms
- Consistent buttons
- Consistent modals
- Consistent alerts
- No unnecessary decorative sections

---

# 36. Suggested UI Theme

A professional hotel-business SaaS palette can use:

```text
Primary: Deep Burgundy / Wine
Secondary: Warm Gold
Background: Very Light Gray
Surface: White
Text: Dark Charcoal
Muted: Gray
Success: Green
Warning: Amber
Danger: Red
```

The exact colors should be finalized during UI implementation.

Use the brand colors consistently rather than applying many unrelated colors.

---

# 37. SaaS Layout

Desktop:

```text
┌─────────────────────────────────────────────────────────┐
│ Sidebar │ Top Header                                    │
│         ├───────────────────────────────────────────────┤
│         │ Page Header                                    │
│         │                                                │
│         │ KPI Cards                                      │
│         │                                                │
│         │ Filters                                        │
│         │                                                │
│         │ Data Table                                     │
│         │                                                │
└─────────┴────────────────────────────────────────────────┘
```

Mobile:

```text
┌───────────────────────────┐
│ Header              ☰     │
├───────────────────────────┤
│ Page Title                │
│                           │
│ KPI Card                  │
│ KPI Card                  │
│                           │
│ Filters                   │
│                           │
│ Responsive Data Cards     │
│                           │
└───────────────────────────┘
```

---

# 38. Responsive Requirements

The application must work on:

- Desktop
- Laptop
- Tablet
- Mobile

Breakpoints should use Bootstrap's responsive system.

Important:

- Tables must remain usable on small screens.
- Upload form must remain accessible.
- PDF viewer must fit the viewport.
- Sidebar becomes an offcanvas/mobile menu.
- Buttons must remain touch-friendly.
- No horizontal page overflow.
- Modals must fit smaller screens.
- Filters should stack cleanly on mobile.

---

# 39. Dashboard Charts

Charts are optional because this is primarily a document portal.

If included, keep them focused on operational upload information:

- Uploads by branch
- Cash vs Card document counts
- Daily upload counts
- Branch upload completion

Never imply financial totals from document counts.

---

# 40. Notifications

Use SaaS-style notifications.

Examples:

```text
Bill uploaded successfully.
```

```text
PDF file is larger than the allowed limit.
```

```text
Only PDF files are allowed.
```

```text
You are not authorized to access this document.
```

```text
Branch created successfully.
```

Use:

- Toasts
- Inline validation
- Confirmation modals
- Empty states

---

# 41. Empty States

Owner:

```text
No branches found.
Create your first branch to get started.
```

Bills:

```text
No bills found for the selected filters.
```

Branch Admin:

```text
No bills uploaded yet.
Upload today's cash or card bill PDF.
```

---

# 42. Error Handling

Handle:

- Invalid login
- Session expiration
- Unauthorized page access
- Unauthorized document access
- Missing PDF
- Invalid PDF
- Oversized PDF
- Upload failure
- Database failure
- Storage failure
- Duplicate submission
- Invalid branch ID
- Deleted document
- Inactive account
- Inactive branch

Never display raw database errors to users.

---

# 43. Security Requirements

## Authentication

- Password hashing using PHP `password_hash()`
- Password verification using `password_verify()`
- Secure sessions
- Session regeneration after login
- Logout destroys session
- Session timeout where appropriate

## Authorization

Every protected endpoint must perform role checks.

## CSRF

All state-changing forms should use CSRF tokens.

Required for:

- Login where appropriate
- Branch create/edit/delete
- Admin create/edit/delete
- Upload
- Delete bill
- Password changes
- Status changes

## SQL Injection

Use PDO prepared statements.

Never concatenate user input into SQL queries.

## XSS

Escape output with appropriate HTML escaping.

## File Upload Security

- PDF-only
- MIME validation
- Extension validation
- File signature validation
- Size limit
- Secure filenames
- Non-executable storage
- Access-controlled download endpoint

## Brute Force Protection

Apply login throttling/rate limiting.

## HTTPS

Production must use HTTPS.

---

# 44. PDF Access Security

This is one of the most important security components.

Do not expose unrestricted URLs such as:

```text
/uploads/branch1/bill.pdf
```

Instead:

```text
/view_bill.php?id=123
```

The server checks:

```text
Is user logged in?
        ↓
What is user's role?
        ↓
If owner → allow authorized bill
        ↓
If branch admin → bill.branch_id must equal user.branch_id
        ↓
If valid → stream PDF
        ↓
Otherwise → 403
```

This prevents branch admins from accessing another branch's documents.

---

# 45. File Download Security

The download endpoint must:

1. Authenticate the user.
2. Fetch the bill record.
3. Check branch/role authorization.
4. Confirm file exists.
5. Set safe response headers.
6. Stream the PDF.
7. Log the download.

Example response behavior:

```text
Content-Type: application/pdf
Content-Disposition: attachment; filename="safe-name.pdf"
```

The actual server path must never be exposed to the user.

---

# 46. Audit Trail

The system should maintain accountability.

For every major action, record:

- User
- Role
- Branch
- Action
- Entity
- Entity ID
- Timestamp
- IP
- User agent where appropriate

This allows the owner to determine:

```text
Who uploaded this?
When was it uploaded?
Which branch?
Which document?
Who downloaded it?
Who deleted it?
```

---

# 47. Owner Settings

Possible settings:

- Owner profile
- Change password
- Maximum PDF size
- Allowed upload type
- Session settings
- System name
- Upload rules

Avoid adding unnecessary configuration until required.

---

# 48. Branch Admin Profile

Branch admin should be able to view:

- Name
- Assigned branch
- Login ID
- Account status
- Last login

Allow password change if required.

Do not allow the admin to change their branch assignment.

---

# 49. Complete Owner Flow

```text
LOGIN
  ↓
OWNER DASHBOARD
  ↓
┌─────────────────────────────────────┐
│ Dashboard                            │
│ Branches                             │
│ Branch Admins                        │
│ Bills                                │
│ Upload Activity                      │
│ Audit Logs                           │
│ Settings                             │
└─────────────────────────────────────┘
```

## Branch flow

```text
Branches
  ↓
Add Branch
  ↓
Branch Created
  ↓
Create/Assign Admin
  ↓
Admin Active
```

## Bill flow

```text
Bills
  ↓
Filter Branch
  ↓
Filter Date
  ↓
Filter Cash/Card
  ↓
View PDF
  ↓
Download PDF
```

---

# 50. Complete Branch Admin Flow

```text
LOGIN
  ↓
BRANCH DASHBOARD
  ↓
UPLOAD BILLS
  ↓
Select Cash/Card
  ↓
Select Date
  ↓
Select PDF
  ↓
Validate
  ↓
Upload
  ↓
Success
  ↓
My Uploads
  ↓
View / Download
```

---

# 51. Page Inventory

## Public

1. Login
2. Unauthorized
3. Session expired
4. Error page

## Owner

5. Owner Dashboard
6. Branch List
7. Create Branch
8. Edit Branch
9. Branch Details
10. Branch Admin List
11. Create Admin
12. Edit Admin
13. Admin Details
14. Bill Management
15. Bill Details
16. PDF Viewer
17. Upload Activity
18. Audit Logs
19. Owner Profile
20. Settings

## Branch Admin

21. Branch Dashboard
22. Upload Bills
23. My Uploads
24. Bill Details
25. PDF Viewer
26. Profile

---

# 52. API/Request Structure

Recommended logical endpoints:

```text
POST   /api/auth/login
POST   /api/auth/logout

GET    /api/branches
POST   /api/branches
GET    /api/branches/{id}
PUT    /api/branches/{id}
DELETE /api/branches/{id}

GET    /api/admins
POST   /api/admins
GET    /api/admins/{id}
PUT    /api/admins/{id}
DELETE /api/admins/{id}

GET    /api/bills
POST   /api/bills/upload
GET    /api/bills/{id}
DELETE /api/bills/{id}

GET    /api/bills/{id}/view
GET    /api/bills/{id}/download

GET    /api/audit-logs
```

The exact implementation can use standard PHP endpoints instead of a formal REST API if the project does not require a separate frontend application.

---

# 53. AJAX Usage

AJAX/fetch can improve SaaS UX.

Good candidates:

- Branch filtering
- Bill filtering
- Admin status changes
- Upload progress
- Table refresh
- Delete confirmation
- Dashboard counts

Do not make every action AJAX-only.

The backend should remain secure and functional independently of the UI.

---

# 54. Upload Progress

For large PDFs, show:

```text
Uploading...
██████████████░░░░ 75%
```

After completion:

```text
Upload complete
```

On failure:

```text
Upload failed — please retry.
```

Prevent double submission while an upload is active.

---

# 55. Performance Plan

Because the system handles PDF documents:

- Paginate bill lists.
- Do not load PDFs inside list pages.
- Load PDF only when requested.
- Store metadata in MySQL.
- Store files on disk/object storage depending on deployment.
- Avoid base64 storage of PDFs in MySQL.
- Do not store PDF binary data in normal MySQL rows unless there is a specific requirement.
- Use indexes for branch/date/type queries.
- Use lazy loading where appropriate.

Recommended architecture:

```text
MySQL
   ↓
Document metadata

File Storage
   ↓
Actual PDF files
```

---

# 56. Database vs File Storage

Do NOT put complete PDF binaries directly into MySQL by default.

Store:

```text
MySQL:
Bill metadata
```

and:

```text
File system:
PDF
```

Example:

```text
MySQL
bill_id = 1024
branch_id = 5
payment_type = card
stored_filename = 8f31c92a.pdf
```

File system:

```text
/storage/uploads/branches/BR005/card/8f31c92a.pdf
```

---

# 57. Backup Plan

Back up both:

1. MySQL database
2. PDF storage

A database-only backup is insufficient because the PDFs are separate files.

Recommended:

```text
Daily DB backup
+
Daily PDF backup
```

Retention policy should be decided by the owner.

---

# 58. Deployment Plan

For a PHP/MySQL environment:

```text
Domain
   ↓
HTTPS
   ↓
PHP Application
   ↓
MySQL
   ↓
Secure PDF Storage
```

Production checklist:

- HTTPS enabled
- Production database created
- Database credentials secured
- Upload directory secured
- PHP upload limits configured
- Error display disabled
- Error logging enabled
- Correct file permissions
- Backup configured
- Owner account created
- Initial branches created
- Admin accounts created
- Test PDF uploaded
- Cross-branch authorization tested

---

# 59. Initial Setup Flow

```text
Install Application
       ↓
Create Database
       ↓
Run Database Schema
       ↓
Create Owner Account
       ↓
Login as Owner
       ↓
Create Branches
       ↓
Create Branch Admins
       ↓
Assign Admins to Branches
       ↓
Branch Admin Login
       ↓
Upload Test Cash PDF
       ↓
Upload Test Card PDF
       ↓
Owner Reviews Documents
       ↓
Production Ready
```

---

# 60. Testing Plan

## Authentication Tests

- Owner login works.
- Admin login works.
- Invalid login rejected.
- Logout works.
- Session expires correctly.
- Disabled user cannot login.

## Authorization Tests

- Admin cannot access Owner pages.
- Admin cannot access another branch.
- Admin cannot manipulate branch ID to access another branch.
- Owner can access all branches.
- Unauthenticated user cannot access protected pages.

## Upload Tests

- Valid PDF uploads.
- Non-PDF rejected.
- Oversized file rejected.
- Empty file rejected.
- Corrupted file rejected.
- Duplicate submission handled.
- Upload metadata saved.
- PDF stored correctly.

## PDF Tests

- Owner can view.
- Owner can download.
- Admin can view own branch PDF.
- Admin can download own branch PDF.
- Admin cannot view another branch PDF.
- Missing PDF handled safely.

## CRUD Tests

- Branch create.
- Branch edit.
- Branch activate/deactivate.
- Admin create.
- Admin edit.
- Admin reset password.
- Admin activate/deactivate.
- Bill delete.

## Responsive Tests

Test:

- Chrome desktop
- Edge desktop
- Android/mobile browser
- Tablet
- Different screen widths

---

# 61. Security Test Cases

Attempt:

```text
?id=OTHER_BRANCH_BILL_ID
```

as a branch admin.

Expected:

```text
403 Unauthorized
```

Attempt:

```text
/owner/dashboard.php
```

as branch admin.

Expected:

```text
403 / redirect
```

Attempt direct PDF path.

Expected:

```text
Access denied unless authorized through protected endpoint.
```

Attempt file upload:

```text
malicious.php
image.jpg
document.exe
```

Expected:

```text
Rejected
```

Attempt SQL injection through filters.

Expected:

```text
Prepared statements prevent injection.
```

Attempt CSRF request.

Expected:

```text
Rejected
```

---

# 62. UX Details

## Buttons

Use consistent hierarchy:

```text
Primary
Secondary
Danger
Ghost
Icon
```

## Forms

- Clear labels
- Required indicators
- Inline validation
- Helpful error messages
- Loading state
- Disabled state

## Tables

- Sticky headers where useful
- Pagination
- Search/filter controls
- Compact action buttons
- Responsive mobile presentation

## Modals

Use for:

- Delete confirmation
- Status changes
- Quick create/edit
- Important warnings

---

# 63. Accessibility

Include:

- Proper labels
- Keyboard navigation
- Focus states
- ARIA labels for icon buttons
- Adequate contrast
- Semantic HTML
- Visible validation errors
- No icon-only critical actions without accessible labels

---

# 64. Important Business Rules

The implementation should enforce these rules:

### Rule 1

One organization has one Owner account.

### Rule 2

Owner can create multiple branches.

### Rule 3

Every branch can have branch admin account(s) according to the configured business rule.

### Rule 4

Branch admins belong to a specific branch.

### Rule 5

Branch admins can upload only PDF bills.

### Rule 6

There are two bill types:

```text
Cash
Card
```

### Rule 7

A branch admin can access only their branch's documents.

### Rule 8

Owner can access all branch documents.

### Rule 9

No OCR is performed.

### Rule 10

No image processing is performed.

### Rule 11

No payment is processed.

### Rule 12

No card details are extracted or stored.

### Rule 13

No bill amount is extracted from PDF.

### Rule 14

No financial calculation is performed from the PDF.

### Rule 15

The application manages documents and their metadata.

---

# 65. Explicitly Out of Scope

Do NOT implement the following unless the business requirements change:

- OCR
- AI document reading
- Image recognition
- PDF text extraction
- Payment gateway
- Real card transaction processing
- Cash transaction processing
- Accounting
- GST calculation
- Invoice calculation
- POS integration
- Bank API integration
- Card gateway integration
- Automatic amount extraction
- Automatic invoice-number extraction
- Automatic customer extraction
- Facial recognition
- Image compression/recognition workflow
- Financial reconciliation
- Online customer payment

The project is a **PDF document upload and management system**.

---

# 66. Recommended MVP

The first production version should contain:

## Owner

- Login
- Dashboard
- Branch CRUD
- Branch Admin CRUD
- Bill list
- Bill filters
- PDF view
- PDF download
- Bill deletion
- Audit logs
- Profile/password

## Branch Admin

- Login
- Dashboard
- Cash PDF upload
- Card PDF upload
- My uploads
- PDF view
- PDF download
- Profile/password

## System

- Authentication
- Authorization
- CSRF
- Secure PDF storage
- MySQL
- Audit logging
- Responsive SaaS UI
- Error handling
- Backup strategy

---

# 67. Development Phases

## Phase 1 — Planning

- Confirm business rules.
- Confirm branch/admin relationship.
- Confirm daily upload rules.
- Confirm PDF size limit.
- Confirm retention/deletion policy.
- Finalize UI design.

## Phase 2 — Database

- Create schema.
- Create indexes.
- Create foreign keys.
- Create seed data.
- Create Owner account.

## Phase 3 — Authentication

- Login
- Logout
- Sessions
- Password hashing
- Role middleware
- Authorization

## Phase 4 — Owner Module

- Dashboard
- Branch CRUD
- Admin CRUD
- Branch details
- Admin management

## Phase 5 — Bill Module

- Upload
- Validation
- Storage
- Metadata
- View
- Download
- Delete
- Filtering
- Pagination

## Phase 6 — Branch Admin Module

- Dashboard
- Upload page
- Upload history
- PDF viewing
- PDF downloading

## Phase 7 — Audit & Security

- Audit logs
- CSRF
- Authorization
- Upload security
- Session security
- Rate limiting
- Access testing

## Phase 8 — SaaS UI

- Responsive sidebar
- Dashboard cards
- Tables
- Filters
- Modals
- Toasts
- Empty states
- Loading states
- Mobile UI

## Phase 9 — Testing

- Functional testing
- Security testing
- Responsive testing
- Upload testing
- Cross-branch authorization testing

## Phase 10 — Production

- HTTPS
- Database
- File storage
- Backups
- Error logging
- Production configuration
- Final security audit

---

# 68. Final End-to-End Process

```text
                         OWNER
                           |
                    Owner Login
                           |
                    Owner Dashboard
                           |
             +-------------+-------------+
             |             |             |
          Branches       Admins         Bills
             |             |             |
       Create Branch   Create Admin     View All
             |             |             |
             +------ Assign Branch -----+
                           |
                           v
                    BRANCH ADMIN
                           |
                       Login
                           |
                  Branch Dashboard
                           |
                    Upload Bills
                           |
              +------------+------------+
              |                         |
          CASH PDF                  CARD PDF
              |                         |
              +------------+------------+
                           |
                      Validate PDF
                           |
                    Secure File Store
                           |
                      MySQL Metadata
                           |
                           v
                         OWNER
                           |
                    Bill Management
                           |
            +--------------+--------------+
            |              |              |
          Filter         View          Download
            |              |              |
          Branch         PDF            PDF
            |
        Date / Type
            |
        Upload Activity
            |
       Audit / History
```

---

# 69. Final Product Definition

The finished Radha Rani Hotel portal should function as a secure, responsive, multi-branch SaaS-style **daily bill PDF management system**.

The Owner has centralized control over branches, branch administrators and uploaded bill documents.

Branch administrators have a deliberately limited workflow focused on uploading their branch's Cash and Card bill PDFs.

The application does not interpret the contents of those PDFs.

The fundamental data flow is:

```text
Branch Admin
    ↓
Select Cash/Card
    ↓
Upload PDF
    ↓
Validate
    ↓
Securely Store PDF
    ↓
Store Metadata in MySQL
    ↓
Owner Portal
    ↓
Filter
    ↓
View PDF
    ↓
Download PDF
```

The architecture should remain simple, secure and maintainable. The application should avoid unnecessary financial-processing features because the required business function is document upload, storage, access control and viewing.

---

# 70. Final Feature Checklist

## Authentication

- [ ] Owner login
- [ ] Branch admin login
- [ ] Logout
- [ ] Session management
- [ ] Password hashing
- [ ] Password change
- [ ] Account status

## Owner

- [ ] Dashboard
- [ ] Branch CRUD
- [ ] Branch status
- [ ] Branch details
- [ ] Admin CRUD
- [ ] Admin assignment
- [ ] Admin status
- [ ] Bill management
- [ ] Branch filtering
- [ ] Payment-type filtering
- [ ] Date filtering
- [ ] PDF viewing
- [ ] PDF downloading
- [ ] Bill deletion
- [ ] Upload activity
- [ ] Audit logs
- [ ] Profile
- [ ] Settings

## Branch Admin

- [ ] Branch dashboard
- [ ] Cash PDF upload
- [ ] Card PDF upload
- [ ] Upload validation
- [ ] Upload history
- [ ] PDF view
- [ ] PDF download
- [ ] Profile
- [ ] Password change

## PDF Management

- [ ] PDF-only validation
- [ ] MIME validation
- [ ] File-size validation
- [ ] Secure filenames
- [ ] Secure storage
- [ ] Metadata storage
- [ ] Protected PDF access
- [ ] View
- [ ] Download
- [ ] Delete/soft-delete
- [ ] Audit trail

## Security

- [ ] Role authorization
- [ ] Branch authorization
- [ ] CSRF protection
- [ ] SQL injection protection
- [ ] XSS protection
- [ ] Session security
- [ ] Login throttling
- [ ] Secure file upload
- [ ] Protected file paths
- [ ] HTTPS
- [ ] Error logging

## UI/UX

- [ ] SaaS sidebar
- [ ] Responsive header
- [ ] Dashboard cards
- [ ] Responsive tables
- [ ] Filters
- [ ] Search
- [ ] Pagination
- [ ] Toasts
- [ ] Modals
- [ ] Empty states
- [ ] Loading states
- [ ] Upload progress
- [ ] Mobile responsive
- [ ] Tablet responsive
- [ ] Desktop responsive
- [ ] Accessibility

## Infrastructure

- [ ] MySQL
- [ ] Secure PDF storage
- [ ] Database indexes
- [ ] Backup database
- [ ] Backup PDF files
- [ ] Production HTTPS
- [ ] Error logging
- [ ] Deployment configuration

---

# 71. One-Sentence Scope

> **Radha Rani Hotel Portal is a secure multi-branch SaaS-style web application where a single Owner manages branches and branch administrators, while each branch administrator uploads Cash and Card bill PDFs and the Owner centrally views, filters, downloads and manages those PDF documents without OCR, image processing, payment processing or PDF content extraction.**
