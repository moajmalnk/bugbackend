-- =============================================================================
-- BugRicer Migration 071b — Seed git username / email + GitHub URL
-- =============================================================================
-- Matches existing users by email (preferred) then username (case-insensitive).
-- Does not overwrite non-empty existing values. LinkedIn left NULL.
-- Requires 071_user_social_git_profiles.sql.
-- Safe to re-run.
-- =============================================================================

SET @db := DATABASE();

-- Helper pattern per person:
--   resolve @uid, then INSERT … ON DUPLICATE KEY UPDATE with COALESCE preserve.

-- ---------------------------------------------------------------------------
-- moajmalnk / Ajmal Nk
-- ---------------------------------------------------------------------------
SET @uid := (
  SELECT id FROM users
  WHERE LOWER(email) = LOWER('moajmalnk@gmail.com')
     OR LOWER(username) = LOWER('moajmalnk')
  ORDER BY CASE WHEN LOWER(email) = LOWER('moajmalnk@gmail.com') THEN 0 ELSE 1 END
  LIMIT 1
);
INSERT INTO user_onboarding_details (user_id, git_username, git_email, github_url)
SELECT @uid, 'moajmalnk', 'moajmalnk@gmail.com', 'https://github.com/moajmalnk'
WHERE @uid IS NOT NULL
ON DUPLICATE KEY UPDATE
  git_username = COALESCE(NULLIF(git_username, ''), VALUES(git_username)),
  git_email = COALESCE(NULLIF(git_email, ''), VALUES(git_email)),
  github_url = COALESCE(NULLIF(github_url, ''), VALUES(github_url));

-- ---------------------------------------------------------------------------
-- moajmalp / Ajmal P
-- ---------------------------------------------------------------------------
SET @uid := (
  SELECT id FROM users
  WHERE LOWER(email) = LOWER('moajmalp@gmail.com')
     OR LOWER(username) = LOWER('moajmalp')
  ORDER BY CASE WHEN LOWER(email) = LOWER('moajmalp@gmail.com') THEN 0 ELSE 1 END
  LIMIT 1
);
INSERT INTO user_onboarding_details (user_id, git_username, git_email, github_url)
SELECT @uid, 'moajmalp', 'moajmalp@gmail.com', 'https://github.com/moajmalp'
WHERE @uid IS NOT NULL
ON DUPLICATE KEY UPDATE
  git_username = COALESCE(NULLIF(git_username, ''), VALUES(git_username)),
  git_email = COALESCE(NULLIF(git_email, ''), VALUES(git_email)),
  github_url = COALESCE(NULLIF(github_url, ''), VALUES(github_url));

-- ---------------------------------------------------------------------------
-- irshadmglm / Irshad
-- ---------------------------------------------------------------------------
SET @uid := (
  SELECT id FROM users
  WHERE LOWER(email) = LOWER('irshadmglm@gmail.com')
     OR LOWER(username) = LOWER('irshadmglm')
  ORDER BY CASE WHEN LOWER(email) = LOWER('irshadmglm@gmail.com') THEN 0 ELSE 1 END
  LIMIT 1
);
INSERT INTO user_onboarding_details (user_id, git_username, git_email, github_url)
SELECT @uid, 'irshadmglm', 'irshadmglm@gmail.com', 'https://github.com/irshadmglm'
WHERE @uid IS NOT NULL
ON DUPLICATE KEY UPDATE
  git_username = COALESCE(NULLIF(git_username, ''), VALUES(git_username)),
  git_email = COALESCE(NULLIF(git_email, ''), VALUES(git_email)),
  github_url = COALESCE(NULLIF(github_url, ''), VALUES(github_url));

-- ---------------------------------------------------------------------------
-- Hashimkaliyadan / Hashim
-- ---------------------------------------------------------------------------
SET @uid := (
  SELECT id FROM users
  WHERE LOWER(email) = LOWER('Mohammadhashim9822@gmail.com')
     OR LOWER(username) = LOWER('Hashimkaliyadan')
  ORDER BY CASE WHEN LOWER(email) = LOWER('Mohammadhashim9822@gmail.com') THEN 0 ELSE 1 END
  LIMIT 1
);
INSERT INTO user_onboarding_details (user_id, git_username, git_email, github_url)
SELECT @uid, 'Hashimkaliyadan', 'Mohammadhashim9822@gmail.com', 'https://github.com/Hashimkaliyadan'
WHERE @uid IS NOT NULL
ON DUPLICATE KEY UPDATE
  git_username = COALESCE(NULLIF(git_username, ''), VALUES(git_username)),
  git_email = COALESCE(NULLIF(git_email, ''), VALUES(git_email)),
  github_url = COALESCE(NULLIF(github_url, ''), VALUES(github_url));

-- ---------------------------------------------------------------------------
-- Shazia757 / Shazia (username only)
-- ---------------------------------------------------------------------------
SET @uid := (
  SELECT id FROM users
  WHERE LOWER(username) = LOWER('Shazia757')
  LIMIT 1
);
INSERT INTO user_onboarding_details (user_id, git_username, github_url)
SELECT @uid, 'Shazia757', 'https://github.com/Shazia757'
WHERE @uid IS NOT NULL
ON DUPLICATE KEY UPDATE
  git_username = COALESCE(NULLIF(git_username, ''), VALUES(git_username)),
  github_url = COALESCE(NULLIF(github_url, ''), VALUES(github_url));

-- ---------------------------------------------------------------------------
-- Rumana123rumi / Rumana
-- ---------------------------------------------------------------------------
SET @uid := (
  SELECT id FROM users
  WHERE LOWER(email) = LOWER('Rumana.np143@gmail.com')
     OR LOWER(username) = LOWER('Rumana123rumi')
  ORDER BY CASE WHEN LOWER(email) = LOWER('Rumana.np143@gmail.com') THEN 0 ELSE 1 END
  LIMIT 1
);
INSERT INTO user_onboarding_details (user_id, git_username, git_email, github_url)
SELECT @uid, 'Rumana123rumi', 'Rumana.np143@gmail.com', 'https://github.com/Rumana123rumi'
WHERE @uid IS NOT NULL
ON DUPLICATE KEY UPDATE
  git_username = COALESCE(NULLIF(git_username, ''), VALUES(git_username)),
  git_email = COALESCE(NULLIF(git_email, ''), VALUES(git_email)),
  github_url = COALESCE(NULLIF(github_url, ''), VALUES(github_url));

-- ---------------------------------------------------------------------------
-- Sareefa-TP / Sareefa (username only)
-- ---------------------------------------------------------------------------
SET @uid := (
  SELECT id FROM users
  WHERE LOWER(username) = LOWER('Sareefa-TP')
  LIMIT 1
);
INSERT INTO user_onboarding_details (user_id, git_username, github_url)
SELECT @uid, 'Sareefa-TP', 'https://github.com/Sareefa-TP'
WHERE @uid IS NOT NULL
ON DUPLICATE KEY UPDATE
  git_username = COALESCE(NULLIF(git_username, ''), VALUES(git_username)),
  github_url = COALESCE(NULLIF(github_url, ''), VALUES(github_url));

-- ---------------------------------------------------------------------------
-- paravathsinan / Sinan Paravath (username only)
-- ---------------------------------------------------------------------------
SET @uid := (
  SELECT id FROM users
  WHERE LOWER(username) = LOWER('paravathsinan')
  LIMIT 1
);
INSERT INTO user_onboarding_details (user_id, git_username, github_url)
SELECT @uid, 'paravathsinan', 'https://github.com/paravathsinan'
WHERE @uid IS NOT NULL
ON DUPLICATE KEY UPDATE
  git_username = COALESCE(NULLIF(git_username, ''), VALUES(git_username)),
  github_url = COALESCE(NULLIF(github_url, ''), VALUES(github_url));

-- ---------------------------------------------------------------------------
-- fahis7808 / fahis (also try username fahis)
-- ---------------------------------------------------------------------------
SET @uid := (
  SELECT id FROM users
  WHERE LOWER(email) = LOWER('afup7808@gmail.com')
     OR LOWER(username) IN (LOWER('fahis7808'), LOWER('fahis'))
  ORDER BY CASE
    WHEN LOWER(email) = LOWER('afup7808@gmail.com') THEN 0
    WHEN LOWER(username) = LOWER('fahis7808') THEN 1
    ELSE 2
  END
  LIMIT 1
);
INSERT INTO user_onboarding_details (user_id, git_username, git_email, github_url)
SELECT @uid, 'fahis7808', 'afup7808@gmail.com', 'https://github.com/fahis7808'
WHERE @uid IS NOT NULL
ON DUPLICATE KEY UPDATE
  git_username = COALESCE(NULLIF(git_username, ''), VALUES(git_username)),
  git_email = COALESCE(NULLIF(git_email, ''), VALUES(git_email)),
  github_url = COALESCE(NULLIF(github_url, ''), VALUES(github_url));

-- ---------------------------------------------------------------------------
-- jidhin01 / Jidhin
-- ---------------------------------------------------------------------------
SET @uid := (
  SELECT id FROM users
  WHERE LOWER(email) = LOWER('hinjit86@gmail.com')
     OR LOWER(username) = LOWER('jidhin01')
  ORDER BY CASE WHEN LOWER(email) = LOWER('hinjit86@gmail.com') THEN 0 ELSE 1 END
  LIMIT 1
);
INSERT INTO user_onboarding_details (user_id, git_username, git_email, github_url)
SELECT @uid, 'jidhin01', 'hinjit86@gmail.com', 'https://github.com/jidhin01'
WHERE @uid IS NOT NULL
ON DUPLICATE KEY UPDATE
  git_username = COALESCE(NULLIF(git_username, ''), VALUES(git_username)),
  git_email = COALESCE(NULLIF(git_email, ''), VALUES(git_email)),
  github_url = COALESCE(NULLIF(github_url, ''), VALUES(github_url));

-- ---------------------------------------------------------------------------
-- shibinshibii / Shibin (also try username shibin)
-- ---------------------------------------------------------------------------
SET @uid := (
  SELECT id FROM users
  WHERE LOWER(email) = LOWER('shibi393493@gmail.com')
     OR LOWER(username) IN (LOWER('shibinshibii'), LOWER('shibin'))
  ORDER BY CASE
    WHEN LOWER(email) = LOWER('shibi393493@gmail.com') THEN 0
    WHEN LOWER(username) = LOWER('shibinshibii') THEN 1
    ELSE 2
  END
  LIMIT 1
);
INSERT INTO user_onboarding_details (user_id, git_username, git_email, github_url)
SELECT @uid, 'shibinshibii', 'shibi393493@gmail.com', 'https://github.com/shibinshibii'
WHERE @uid IS NOT NULL
ON DUPLICATE KEY UPDATE
  git_username = COALESCE(NULLIF(git_username, ''), VALUES(git_username)),
  git_email = COALESCE(NULLIF(git_email, ''), VALUES(git_email)),
  github_url = COALESCE(NULLIF(github_url, ''), VALUES(github_url));

-- ---------------------------------------------------------------------------
-- fathimanihala003 / Nihala
-- ---------------------------------------------------------------------------
SET @uid := (
  SELECT id FROM users
  WHERE LOWER(email) = LOWER('nihalaf594@gmail.com')
     OR LOWER(username) = LOWER('fathimanihala003')
  ORDER BY CASE WHEN LOWER(email) = LOWER('nihalaf594@gmail.com') THEN 0 ELSE 1 END
  LIMIT 1
);
INSERT INTO user_onboarding_details (user_id, git_username, git_email, github_url)
SELECT @uid, 'fathimanihala003', 'nihalaf594@gmail.com', 'https://github.com/fathimanihala003'
WHERE @uid IS NOT NULL
ON DUPLICATE KEY UPDATE
  git_username = COALESCE(NULLIF(git_username, ''), VALUES(git_username)),
  git_email = COALESCE(NULLIF(git_email, ''), VALUES(git_email)),
  github_url = COALESCE(NULLIF(github_url, ''), VALUES(github_url));

-- ---------------------------------------------------------------------------
-- marvakt / Fathima marva
-- ---------------------------------------------------------------------------
SET @uid := (
  SELECT id FROM users
  WHERE LOWER(email) = LOWER('ktmarwa51@gmail.com')
     OR LOWER(username) = LOWER('marvakt')
  ORDER BY CASE WHEN LOWER(email) = LOWER('ktmarwa51@gmail.com') THEN 0 ELSE 1 END
  LIMIT 1
);
INSERT INTO user_onboarding_details (user_id, git_username, git_email, github_url)
SELECT @uid, 'marvakt', 'ktmarwa51@gmail.com', 'https://github.com/marvakt'
WHERE @uid IS NOT NULL
ON DUPLICATE KEY UPDATE
  git_username = COALESCE(NULLIF(git_username, ''), VALUES(git_username)),
  git_email = COALESCE(NULLIF(git_email, ''), VALUES(git_email)),
  github_url = COALESCE(NULLIF(github_url, ''), VALUES(github_url));

-- Verify:
-- SELECT u.username, u.email, d.git_username, d.git_email, d.github_url, d.linkedin_url
-- FROM users u
-- LEFT JOIN user_onboarding_details d ON d.user_id = u.id
-- WHERE d.git_username IS NOT NULL
-- ORDER BY u.username;
