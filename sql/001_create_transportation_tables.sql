-- ============================================================================
-- 001_create_transportation_tables.sql
-- Módulo de Transportación — OCDS Platform
-- Tablas para el flujo completo de recepción, inspección y carga de tráileres
-- Run on: [OLEspresso].[dbo]
-- ============================================================================
USE [OLEspresso];
GO

-- ─── Gate Catalog ────────────────────────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = N'transp_Gate')
BEGIN
    CREATE TABLE [dbo].[transp_Gate] (
        [GateID]      INT IDENTITY(1,1) PRIMARY KEY,
        [GateName]    NVARCHAR(50)  NOT NULL,
        [Description] NVARCHAR(200) NULL,
        [Active]      BIT NOT NULL DEFAULT 1,
        [SortOrder]   INT NOT NULL DEFAULT 0,
        [CreatedAt]   DATETIME NOT NULL DEFAULT GETDATE()
    );
    PRINT 'Created table: transp_Gate';
END
GO

-- Seed default gates (40+)
IF NOT EXISTS (SELECT 1 FROM [dbo].[transp_Gate])
BEGIN
    DECLARE @i INT = 1;
    WHILE @i <= 45
    BEGIN
        INSERT INTO [dbo].[transp_Gate] ([GateName], [SortOrder])
        VALUES (N'Gate ' + CAST(@i AS NVARCHAR), @i);
        SET @i = @i + 1;
    END
    PRINT 'Seeded 45 gates.';
END
GO

-- ─── BI Orders (local cache of orders from BI database) ─────────────────────
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = N'transp_BiOrder')
BEGIN
    CREATE TABLE [dbo].[transp_BiOrder] (
        [BiOrderID]   INT IDENTITY(1,1) PRIMARY KEY,
        [OrderDate]   DATE NOT NULL,
        [ExternalID]  NVARCHAR(100) NOT NULL,
        [OrderType]   NVARCHAR(100) NULL,
        [TruckType]   NVARCHAR(100) NULL,
        [PioNumber]   NVARCHAR(100) NULL,
        [ProgressPct] DECIMAL(5,2)  NULL DEFAULT 0,
        [Notes]       NVARCHAR(500) NULL,
        [IsActive]    BIT NOT NULL DEFAULT 1,
        [ImportedAt]  DATETIME NOT NULL DEFAULT GETDATE(),
        [ImportedBy]  NVARCHAR(100) NOT NULL
    );
    CREATE INDEX IX_transp_BiOrder_Date ON [dbo].[transp_BiOrder]([OrderDate]);
    PRINT 'Created table: transp_BiOrder';
END
GO

-- ─── Schedules (Logistics creates, groups orders + carrier + time window) ────
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = N'transp_Schedule')
BEGIN
    CREATE TABLE [dbo].[transp_Schedule] (
        [ScheduleID]   INT IDENTITY(1,1) PRIMARY KEY,
        [ScheduleDate] DATE NOT NULL,
        [CarrierName]  NVARCHAR(200) NOT NULL,
        [TruckType]    NVARCHAR(100) NULL,
        [WindowStart]  NVARCHAR(10)  NULL,   -- HH:MM
        [WindowEnd]    NVARCHAR(10)  NULL,   -- HH:MM
        [Notes]        NVARCHAR(500) NULL,
        [Status]       NVARCHAR(50)  NOT NULL DEFAULT 'Active',  -- Active, Cancelled, Completed
        [CreatedBy]    NVARCHAR(100) NOT NULL,
        [CreatedAt]    DATETIME NOT NULL DEFAULT GETDATE(),
        [UpdatedBy]    NVARCHAR(100) NULL,
        [UpdatedAt]    DATETIME NULL
    );
    CREATE INDEX IX_transp_Schedule_Date ON [dbo].[transp_Schedule]([ScheduleDate]);
    PRINT 'Created table: transp_Schedule';
END
GO

-- ─── Schedule-Order Link ─────────────────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = N'transp_ScheduleOrder')
BEGIN
    CREATE TABLE [dbo].[transp_ScheduleOrder] (
        [ScheduleOrderID] INT IDENTITY(1,1) PRIMARY KEY,
        [ScheduleID]      INT NOT NULL REFERENCES [dbo].[transp_Schedule]([ScheduleID]),
        [BiOrderID]       INT NULL REFERENCES [dbo].[transp_BiOrder]([BiOrderID]),
        [ExternalOrderID] NVARCHAR(100) NULL,  -- direct ID entry when BI not linked
        [PioNumber]       NVARCHAR(100) NULL,  -- entered manually by Logistics
        [HasPio]          BIT NOT NULL DEFAULT 0,
        [AddedBy]         NVARCHAR(100) NOT NULL,
        [AddedAt]         DATETIME NOT NULL DEFAULT GETDATE()
    );
    PRINT 'Created table: transp_ScheduleOrder';
END
GO

-- ─── Truck Lifecycle (main record, Phase 1–4) ────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = N'transp_Truck')
BEGIN
    CREATE TABLE [dbo].[transp_Truck] (
        [TruckID]        INT IDENTITY(1,1) PRIMARY KEY,
        [TruckDate]      DATE NOT NULL,
        [ScheduleID]     INT NULL REFERENCES [dbo].[transp_Schedule]([ScheduleID]),

        -- Vehicle info
        [TruckNumber]    NVARCHAR(100) NOT NULL,
        [TrailerNumber]  NVARCHAR(100) NULL,
        [LicensePlate]   NVARCHAR(100) NULL,
        [CarrierName]    NVARCHAR(200) NOT NULL,

        -- Phase 1: Driver check-in
        [DriverName]     NVARCHAR(200) NULL,
        [DriverLicense]  NVARCHAR(100) NULL,
        [DriverPhone]    NVARCHAR(100) NULL,
        [ArrivalTime]    DATETIME NULL,
        [Phase1By]       NVARCHAR(100) NULL,
        [Phase1At]       DATETIME NULL,

        -- Phase tracking
        [CurrentPhase]   INT NOT NULL DEFAULT 1,
        [Status]         NVARCHAR(50) NOT NULL DEFAULT 'Phase1',
        -- Status values: Phase1, Phase2, Phase3, Phase4, Completed, Cancelled

        [Notes]          NVARCHAR(1000) NULL,

        -- Phase 4: Closure
        [Phase4Notes]    NVARCHAR(1000) NULL,
        [Phase4By]       NVARCHAR(100) NULL,
        [Phase4At]       DATETIME NULL,

        -- Metadata
        [CreatedBy]      NVARCHAR(100) NOT NULL,
        [CreatedAt]      DATETIME NOT NULL DEFAULT GETDATE(),
        [UpdatedBy]      NVARCHAR(100) NULL,
        [UpdatedAt]      DATETIME NULL
    );
    CREATE INDEX IX_transp_Truck_Date ON [dbo].[transp_Truck]([TruckDate]);
    CREATE INDEX IX_transp_Truck_Status ON [dbo].[transp_Truck]([Status]);
    PRINT 'Created table: transp_Truck';
END
GO

-- ─── Inspections (Phase 2 — multiple attempts allowed) ──────────────────────
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = N'transp_Inspection')
BEGIN
    CREATE TABLE [dbo].[transp_Inspection] (
        [InspectionID]   INT IDENTITY(1,1) PRIMARY KEY,
        [TruckID]        INT NOT NULL REFERENCES [dbo].[transp_Truck]([TruckID]),
        [AttemptNumber]  INT NOT NULL DEFAULT 1,

        -- Checklist (1 = pass, 0 = fail)
        [CheckOdors]     BIT NOT NULL DEFAULT 0,   -- Sin olores extraños
        [CheckHoles]     BIT NOT NULL DEFAULT 0,   -- Sin hoyos piso/techo/paredes
        [CheckForeign]   BIT NOT NULL DEFAULT 0,   -- Sin materiales extraños
        [CheckChemicals] BIT NOT NULL DEFAULT 0,   -- Sin residuos químicos
        [CheckFloor]     BIT NOT NULL DEFAULT 0,   -- Piso limpio y seco

        [Result]         NVARCHAR(20) NOT NULL,    -- APPROVED, REJECTED
        [Comments]       NVARCHAR(1000) NULL,      -- required when REJECTED
        [InspectedBy]    NVARCHAR(100) NOT NULL,
        [InspectedAt]    DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE INDEX IX_transp_Inspection_TruckID ON [dbo].[transp_Inspection]([TruckID]);
    PRINT 'Created table: transp_Inspection';
END
GO

-- ─── Gate Assignments (Phase 3 — history of assignments per truck) ───────────
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = N'transp_GateAssignment')
BEGIN
    CREATE TABLE [dbo].[transp_GateAssignment] (
        [AssignmentID]        INT IDENTITY(1,1) PRIMARY KEY,
        [TruckID]             INT NOT NULL REFERENCES [dbo].[transp_Truck]([TruckID]),
        [GateID]              INT NOT NULL REFERENCES [dbo].[transp_Gate]([GateID]),
        [AssignedBy]          NVARCHAR(100) NOT NULL,
        [AssignedAt]          DATETIME NOT NULL DEFAULT GETDATE(),
        [IsActive]            BIT NOT NULL DEFAULT 1,

        -- Cancellation / reassignment
        [CancelledBy]         NVARCHAR(100) NULL,
        [CancelledAt]         DATETIME NULL,
        [CancelReason]        NVARCHAR(500) NULL,

        -- Shipping: confirm truck at gate
        [ShippingConfirmedBy] NVARCHAR(100) NULL,
        [ShippingConfirmedAt] DATETIME NULL,

        -- Shipping: orders validated before loading
        [OrdersValidatedBy]   NVARCHAR(100) NULL,
        [OrdersValidatedAt]   DATETIME NULL,

        -- Shipping: close loading (end of Phase 3)
        [LoadingClosedBy]     NVARCHAR(100) NULL,
        [LoadingClosedAt]     DATETIME NULL
    );
    CREATE INDEX IX_transp_GateAssignment_TruckID ON [dbo].[transp_GateAssignment]([TruckID]);
    CREATE INDEX IX_transp_GateAssignment_GateID  ON [dbo].[transp_GateAssignment]([GateID]);
    PRINT 'Created table: transp_GateAssignment';
END
GO

-- ─── Audit Log ────────────────────────────────────────────────────────────────
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = N'transp_AuditLog')
BEGIN
    CREATE TABLE [dbo].[transp_AuditLog] (
        [LogID]     INT IDENTITY(1,1) PRIMARY KEY,
        [TruckID]   INT NULL,
        [Action]    NVARCHAR(100) NOT NULL,
        [TableName] NVARCHAR(100) NULL,
        [RecordID]  INT NULL,
        [OldData]   NVARCHAR(MAX) NULL,
        [NewData]   NVARCHAR(MAX) NULL,
        [ActionBy]  NVARCHAR(100) NOT NULL,
        [ActionAt]  DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE INDEX IX_transp_AuditLog_TruckID ON [dbo].[transp_AuditLog]([TruckID]);
    PRINT 'Created table: transp_AuditLog';
END
GO

PRINT '=== Transportation module schema ready ===';
GO
