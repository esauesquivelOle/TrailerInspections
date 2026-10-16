-- ============================================================================
-- 004_user_roles.sql
-- Role-based locks: who does each step of the Transportation flow.
-- A user can hold several roles. No role = view only.
-- Run on the module database with an account that can create tables (db_owner)
-- ============================================================================

IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = N'transp_UserRole')
BEGIN
    CREATE TABLE [dbo].[transp_UserRole] (
        [UserRoleID] INT IDENTITY(1,1) PRIMARY KEY,
        [Username]   NVARCHAR(100) NOT NULL,
        [Role]       NVARCHAR(30)  NOT NULL,   -- ADMIN, SUPERVISOR, SECURITY, INSPECTOR, LOGISTICS, WAREHOUSE, SHIPPING
        [AssignedBy] NVARCHAR(100) NOT NULL,
        [AssignedAt] DATETIME NOT NULL DEFAULT GETDATE(),
        CONSTRAINT UQ_transp_UserRole UNIQUE ([Username], [Role])
    );
    PRINT 'Created table: transp_UserRole';
END
GO

-- First administrator: php tools/grant_role.php <username> ADMIN

PRINT '=== Migration 004 complete ===';
GO
