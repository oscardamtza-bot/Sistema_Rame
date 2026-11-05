-- Revisa primero los conflictos existentes:
-- SELECT reserved_at, COUNT(*) FROM (
--   SELECT CASE WHEN status='reschedule_requested' AND proposed_at IS NOT NULL THEN proposed_at ELSE scheduled_at END AS reserved_at
--   FROM maintenance WHERE status<>'cancelled'
-- ) AS active_slots GROUP BY reserved_at HAVING COUNT(*)>1;
-- La migración no cambia filas existentes. La columna derivada reserva el horario
-- efectivo (propuesta pendiente o fecha programada) y libera las cancelaciones.
ALTER TABLE maintenance
    ADD COLUMN reserved_slot DATETIME GENERATED ALWAYS AS (
        CASE
            WHEN status='cancelled' THEN NULL
            WHEN status='reschedule_requested' AND proposed_at IS NOT NULL THEN proposed_at
            ELSE scheduled_at
        END
    ) STORED,
    ADD UNIQUE KEY uq_maintenance_reserved_slot (reserved_slot);
