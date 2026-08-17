-- Allow the same person to be project lead (manager) and developer on one project.

ALTER TABLE `project_members`
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (`project_id`, `user_id`, `role`);
