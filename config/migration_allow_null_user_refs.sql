-- Migration to allow NULL values for user references to support force delete
-- This allows setting user references to NULL when a user is force deleted

-- Modify bugs table to allow NULL for reported_by
ALTER TABLE bugs MODIFY COLUMN reported_by VARCHAR(36) NULL;

-- Modify bug_attachments table to allow NULL for uploaded_by  
ALTER TABLE bug_attachments MODIFY COLUMN uploaded_by VARCHAR(36) NULL;

-- Activity log should probably be deleted when user is deleted, so no change needed
-- Activities table already has ON DELETE CASCADE, so no change needed

-- Note: projects.created_by already allows NULL 