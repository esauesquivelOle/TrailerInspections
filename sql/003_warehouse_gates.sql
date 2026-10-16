-- ============================================================================
-- 003_warehouse_gates.sql
-- Warehouse: gate blocking (safety) + orders assigned to the truck at the gate
-- Run on the module database with an account that can create tables (db_owner)
-- ============================================================================

-- ─── Gate block status ───────────────────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM sys.columns
               WHERE object_id = OBJECT_ID(N'dbo.transp_Gate') AND name = N'IsBlocked')
BEGIN
    ALTER TABLE [dbo].[transp_Gate]
        ADD [IsBlocked]   BIT NOT NULL CONSTRAINT DF_transp_Gate_IsBlocked DEFAULT 0,
            [BlockReason] NVARCHAR(100) NULL,
            [BlockNotes]  NVARCHAR(500) NULL,
            [BlockedBy]   NVARCHAR(100) NULL,
            [BlockedAt]   DATETIME NULL;
    PRINT 'Added block columns to transp_Gate';
END
GO

-- ─── Gate block history ──────────────────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = N'transp_GateBlockLog')
BEGIN
    CREATE TABLE [dbo].[transp_GateBlockLog] (
        [LogID]     INT IDENTITY(1,1) PRIMARY KEY,
        [GateID]    INT NOT NULL REFERENCES [dbo].[transp_Gate]([GateID]),
        [Action]    NVARCHAR(20)  NOT NULL,      -- BLOCK, UNBLOCK
        [Reason]    NVARCHAR(100) NULL,
        [Notes]     NVARCHAR(500) NULL,
        [ActionBy]  NVARCHAR(100) NOT NULL,
        [ActionAt]  DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE INDEX IX_transp_GateBlockLog_GateID ON [dbo].[transp_GateBlockLog]([GateID]);
    PRINT 'Created table: transp_GateBlockLog';
END
GO

-- ─── Orders loaded on a truck (assigned by Warehouse with the gate) ─────────
IF NOT EXISTS (SELECT 1 FROM sys.columns
               WHERE object_id = OBJECT_ID(N'dbo.transp_ScheduleOrder') AND name = N'TruckID')
BEGIN
    ALTER TABLE [dbo].[transp_ScheduleOrder]
        ADD [TruckID]        INT NULL CONSTRAINT FK_transp_ScheduleOrder_Truck REFERENCES [dbo].[transp_Truck]([TruckID]),
            [LoadAssignedBy] NVARCHAR(100) NULL,
            [LoadAssignedAt] DATETIME NULL;
    PRINT 'Added truck columns to transp_ScheduleOrder';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_transp_ScheduleOrder_TruckID')
    CREATE INDEX IX_transp_ScheduleOrder_TruckID ON [dbo].[transp_ScheduleOrder]([TruckID]);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_transp_GateAssignment_Active')
    CREATE INDEX IX_transp_GateAssignment_Active ON [dbo].[transp_GateAssignment]([IsActive], [GateID]) INCLUDE ([TruckID], [LoadingClosedAt]);
GO

PRINT '=== Migration 003 complete ===';
GO
