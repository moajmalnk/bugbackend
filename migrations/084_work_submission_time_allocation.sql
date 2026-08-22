-- Checkout time allocation (lunch / breaks / Growth Glimpse / other) per work day.

ALTER TABLE `work_submissions`
  ADD COLUMN `time_allocation` JSON NULL DEFAULT NULL
    AFTER `project_updates`;
