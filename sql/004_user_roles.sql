-- ============================================================================
-- 004_user_roles.sql
-- Role-based locks: who does each step of the Transportation flow.
-- A user can hold several roles. No role = view only.
-- Run on: [OLEspresso].[dbo] with a db_owner account (IIS identity has no DDL)
-- ============================================================================
USE [OLEspresso];
GO

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

-- Module owner starts as administrator so roles can be handed out from the UI
IF NOT EXISTS (SELECT 1 FROM [dbo].[transp_UserRole] WHERE [Username] = N'eesquivel' AND [Role] = N'ADMIN')
    INSERT INTO [dbo].[transp_UserRole] ([Username], [Role], [AssignedBy]) VALUES (N'eesquivel', N'ADMIN', N'migration-004');
GO

PRINT '=== Migration 004 complete ===';
GO
