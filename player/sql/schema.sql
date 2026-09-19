-- Edukors Graph player -- MySQL schema.
--
-- The course JSON is stored whole, in `course.doc`. The schema of a course is
-- the contract of the project: taking it apart into tables would only create a
-- second, diverging description of it. Only what listing and routing need is
-- copied out into columns.
--
-- It creates no database, because on shared hosting you do not get to choose
-- the name of one -- the control panel hands you something like
-- `u123456789_courses`. Create the database first, then apply this to it:
--
--   mysql -u <user> -p <database> < sql/schema.sql
--
-- On your own machine, where there is no panel, the database is yours to name:
--
--   mysql -u root -e "CREATE DATABASE edukors_graphs
--     DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--   mysql -u root edukors_graphs < sql/schema.sql


-- The shelves the admin lists courses on. Nothing the student sees reads this
-- table: it groups and orders the list in the admin, and a course belongs to
-- at most one of them. It is declared first because `course` points at it, and
-- a foreign key cannot name a table that does not exist yet.
CREATE TABLE IF NOT EXISTS category (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title      VARCHAR(160) NOT NULL,
  sort_order INT          NOT NULL DEFAULT 0,  -- smallest first; ties fall back to the title
  UNIQUE KEY uk_category (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- One row per imported version of a course.
CREATE TABLE IF NOT EXISTS course (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_uuid     CHAR(36)     NOT NULL,           -- info.course-id
  version         VARCHAR(20)  NOT NULL,           -- info.version
  title           VARCHAR(255) NOT NULL,           -- the source language's title, or
                                                   -- the name given in the admin
  author          VARCHAR(255) NOT NULL,
  source_language VARCHAR(5)   NOT NULL,
  languages       VARCHAR(120) NOT NULL,           -- csv: source-language + other-languages
  start_node      VARCHAR(16)  NOT NULL,           -- info.start
  doc             LONGTEXT     NOT NULL,           -- the course JSON, as delivered
  warnings        TEXT         NULL,               -- import warnings, shown in the admin
  category_id     INT UNSIGNED NULL,               -- category.id; NULL = on no shelf
  sort_order      INT          NOT NULL DEFAULT 0, -- smallest first; ties fall back to the title
  status          ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  created_at      DATETIME     NOT NULL,
  UNIQUE KEY uk_course (course_uuid, version),
  KEY ix_status (status),
  KEY ix_listing (category_id, sort_order),
  -- Emptying a shelf leaves the courses on it alone; they are simply no longer
  -- on one. This is the only foreign key in the schema, and it is here because
  -- a course pointing at a category that was deleted would have no meaning.
  CONSTRAINT fk_course_category FOREIGN KEY (category_id)
    REFERENCES category (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- An LMS registered to launch courses. One row per deployment.
CREATE TABLE IF NOT EXISTS lti_platform (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(160) NOT NULL,           -- "Moodle of school X"
  issuer          VARCHAR(255) NOT NULL,           -- the `iss` of the platform
  client_id       VARCHAR(255) NOT NULL,
  deployment_id   VARCHAR(255) NOT NULL,
  auth_login_url  VARCHAR(500) NOT NULL,           -- OIDC authorization endpoint
  jwks_url        VARCHAR(500) NOT NULL,
  jwks_cache      TEXT         NULL,               -- last key set fetched
  jwks_fetched_at DATETIME     NULL,
  created_at      DATETIME     NOT NULL,
  UNIQUE KEY uk_platform (issuer, client_id, deployment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- state/nonce of an OIDC login, kept until the launch answers. Expires in 10 min.
CREATE TABLE IF NOT EXISTS lti_launch (
  state       CHAR(43)     PRIMARY KEY,
  nonce       CHAR(43)     NOT NULL,
  platform_id INT UNSIGNED NOT NULL,
  course_uuid CHAR(36)     NULL,
  used_at     DATETIME     NULL,
  created_at  DATETIME     NOT NULL,
  KEY ix_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- A student, as the LMS presents them. There is no local sign-up: LTI is the
-- only way in, so (platform, subject) is the identity.
CREATE TABLE IF NOT EXISTS student (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  platform_id  INT UNSIGNED NOT NULL,
  subject      VARCHAR(255) NOT NULL,              -- the `sub` claim of the id_token
  name         VARCHAR(255) NULL,
  email        VARCHAR(255) NULL,
  locale       VARCHAR(10)  NULL,                  -- launch_presentation.locale
  created_at   DATETIME     NOT NULL,
  last_seen_at DATETIME     NOT NULL,
  UNIQUE KEY uk_student (platform_id, subject)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Where a student is in a course. `state` is the literal mirror of the object
-- the player keeps in localStorage, which is what lets any device resume.
CREATE TABLE IF NOT EXISTS progress (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id   INT UNSIGNED NOT NULL,
  course_uuid  CHAR(36)     NOT NULL,
  course_id    INT UNSIGNED NOT NULL,              -- the exact version being played
  lang         VARCHAR(5)   NOT NULL,
  current_node VARCHAR(16)  NULL,                  -- NULL = the course is finished
  percent      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  state        MEDIUMTEXT   NOT NULL,              -- {lang,isLanguageChosen,currentId,history,vars,answers}
  started_at   DATETIME     NOT NULL,
  updated_at   DATETIME     NOT NULL,
  finished_at  DATETIME     NULL,
  UNIQUE KEY uk_progress (student_id, course_uuid),
  KEY ix_course (course_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- The server's own record of what each node produced. api/ai.php writes the
-- generated text here before the student sees it -- that is what freezes a
-- dynamic node and what stops a page reload from paying for a second call.
CREATE TABLE IF NOT EXISTS node_state (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  progress_id INT UNSIGNED NOT NULL,
  node_id     VARCHAR(16)  NOT NULL,
  node_type   VARCHAR(16)  NOT NULL,
  generated_text MEDIUMTEXT NULL,                  -- frozen output of dynamic-md / dynamic-html
                                                   -- ('generated' alone is a reserved word in MySQL)
  answer      MEDIUMTEXT   NULL,                   -- JSON: the raw answer of the student
  score       SMALLINT     NULL,
  feedback    MEDIUMTEXT   NULL,
  visits      INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at  DATETIME     NOT NULL,
  UNIQUE KEY uk_node_state (progress_id, node_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Every call sent to the model: the rate limit counts rows here, and so does
-- whoever wants to know what the courses are costing.
CREATE TABLE IF NOT EXISTS ai_call (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  progress_id INT UNSIGNED NULL,
  node_id     VARCHAR(16)  NOT NULL,
  kind        ENUM('generate','grade') NOT NULL,
  model       VARCHAR(80)  NOT NULL,
  tokens_in   INT UNSIGNED NULL,
  tokens_out  INT UNSIGNED NULL,
  cost        DECIMAL(12,8) NULL,  -- in dollars, as OpenRouter reports it; NULL when it did not
  ok          TINYINT(1)   NOT NULL,
  error       VARCHAR(255) NULL,
  created_at  DATETIME     NOT NULL,
  KEY ix_rate (progress_id, created_at),
  KEY ix_day (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


