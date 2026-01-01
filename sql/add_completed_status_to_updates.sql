-- Migration: Add 'completed' status to updates table
-- This allows approved updates to be marked as completed

ALTER TABLE `updates` 
MODIFY COLUMN `status` ENUM('pending', 'approved', 'declined', 'completed') DEFAULT 'pending';
