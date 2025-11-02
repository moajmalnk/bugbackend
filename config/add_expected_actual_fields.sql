-- Add Expected Result and Actual Result fields to bugs table
ALTER TABLE bugs 
ADD COLUMN expected_result TEXT NULL AFTER description,
ADD COLUMN actual_result TEXT NULL AFTER expected_result;

-- Add indexes for better performance
CREATE INDEX idx_bugs_expected_result ON bugs(expected_result(100));
CREATE INDEX idx_bugs_actual_result ON bugs(actual_result(100));
