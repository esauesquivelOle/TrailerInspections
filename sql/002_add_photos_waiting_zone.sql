-- ============================================================================
-- 002_add_photos_waiting_zone.sql
-- Agrega tabla de fotos de inspección y campos de zona de espera en tráiler
-- Run on: [OLEspresso].[dbo]
-- ============================================================================
USE [OLEspresso];
GO

-- ─── Fotos de inspección (evidencia de rechazo) ──────────────────────────────
IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = N'transp_InspectionPhoto')
BEGIN
    CREATE TABLE [dbo].[transp_InspectionPhoto] (
        [PhotoID]        INT IDENTITY(1,1) PRIMARY KEY,
        [InspectionID]   INT NOT NULL REFERENCES [dbo].[transp_Inspection]([InspectionID]),
        [TruckID]        INT NOT NULL REFERENCES [dbo].[transp_Truck]([TruckID]),
        [FileName]       NVARCHAR(500) NOT NULL,
        [StoredFileName] NVARCHAR(500) NOT NULL,
        [FileSize]       INT NULL,
        [Notes]          NVARCHAR(500) NULL,
        [UploadedBy]     NVARCHAR(100) NOT NULL,
        [UploadedAt]     DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE INDEX IX_transp_InspectionPhoto_InspectionID ON [dbo].[transp_InspectionPhoto]([InspectionID]);
    CREATE INDEX IX_transp_InspectionPhoto_TruckID      ON [dbo].[transp_InspectionPhoto]([TruckID]);
    PRINT 'Created table: transp_InspectionPhoto';
END
GO

-- ─── Zona de espera/reparación en transp_Truck ───────────────────────────────
IF NOT EXISTS (SELECT 1 FROM sys.columns
               WHERE object_id = OBJECT_ID(N'transp_Truck') AND name = N'WaitingZone')
BEGIN
    ALTER TABLE [dbo].[transp_Truck]
        ADD [WaitingZone]       NVARCHAR(200) NULL,
            [WaitingNotes]      NVARCHAR(500) NULL,
            [SentToWaitingBy]   NVARCHAR(100) NULL,
            [SentToWaitingAt]   DATETIME NULL,
            [RecalledBy]        NVARCHAR(100) NULL,
            [RecalledAt]        DATETIME NULL;
    PRINT 'Added waiting zone columns to transp_Truck';
END
GO

PRINT '=== Migration 002 complete ===';
GO
