-- Import 38 press contacts from "press_contacts - press_contacts.csv" and tag them PR.
-- Mirrors UserService::createContact(): ROLE_MEMBER, random unknown password, not opted in,
-- created by chris@harvst.co.uk, plus a "Contact added" note. Anyone already on file
-- (matched on email, email2 or email3) is left as-is and just gets the PR tag.

-- Created outside the transaction (GTID-safe); collation matches `user` so the email joins work.
CREATE TEMPORARY TABLE import_contacts (
  email      VARCHAR(180) NOT NULL,
  first_name VARCHAR(100) DEFAULT NULL,
  last_name  VARCHAR(100) DEFAULT NULL,
  company    VARCHAR(150) DEFAULT NULL,
  is_new     TINYINT(1)   NOT NULL DEFAULT 0
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

START TRANSACTION;

SET @admin := (SELECT id FROM `user` WHERE email = 'chris@lend-engine.com' LIMIT 1);
SET @pr_tag := (SELECT id FROM tag WHERE name = 'PR');

INSERT INTO import_contacts (email, first_name, last_name, company) VALUES
  ('iola.wyn@bbc.co.uk', 'Iola', 'Wyn', 'BBC Wales'),
  ('aled.scourfield@bbc.co.uk', 'Aled', 'Scourfield', 'BBC Wales'),
  ('newsonline.wales@bbc.co.uk', NULL, NULL, 'BBC Wales News'),
  ('dylanh@cambrian-news.co.uk', 'Dylan', NULL, 'Cambrian News'),
  ('journal.star@swwmedia.co.uk', NULL, NULL, 'Carmarthen Journal / Llanelli Star'),
  ('carole.green@itv.com', 'Carole', 'Green', 'ITV Wales'),
  ('wales@itv.com', NULL, NULL, 'ITV Wales'),
  ('ian.lewis@mediawales.co.uk', 'Ian', 'Lewis', 'Media Wales'),
  ('robert.harries@mediawales.co.uk', 'Robert', 'Harries', 'Media Wales'),
  ('rachael.misstear@mediawales.co.uk', 'Rachael', 'Misstear', 'Media Wales'),
  ('becky.hotchin@gwent-wales.co.uk', 'Becky', 'Hotchin', 'Newsquest'),
  ('adam.hughes@newsquest.co.uk', 'Adam', 'Hughes', 'Newsquest'),
  ('clare.snowdon@newsquest.co.uk', 'Clare', 'Snowdon', 'Newsquest'),
  ('fiona.phillips@newsquest.co.uk', 'Fiona', 'Phillips', 'Newsquest'),
  ('gavin.thompson@newsquest.co.uk', 'Gavin', 'Thompson', 'Newsquest'),
  ('katyldr@newsquest.co.uk', 'Katy', NULL, 'Newsquest'),
  ('megan.hunter@newsquest.co.uk', 'Megan', 'Hunter', 'Newsquest'),
  ('michael.lewis@newsquest.co.uk', 'Michael', 'Lewis', 'Newsquest'),
  ('jack.riley@newsquest.co.uk', 'Jack', 'Riley', 'Newsquest'),
  ('megan@herald.email', 'Megan', NULL, 'Pembrokeshire Herald'),
  ('editor@pembrokeshire-herald.com', NULL, NULL, 'Pembrokeshire Herald'),
  ('studio@radiopembrokeshire.com', NULL, NULL, 'Radio Pembrokeshire'),
  ('editor@thetenbyobserver.co.uk', NULL, NULL, 'Tenby Observer'),
  ('neil.dickinson@tenby-today.co.uk', 'Neil', 'Dickinson', 'Tenby Observer'),
  ('paul.evans@tenby-today.co.uk', 'Paul', 'Evans', 'Tenby Observer'),
  ('lee.macgregor@trinitymirror.com', 'Lee', 'MacGregor', 'Trinity Mirror'),
  ('wfeditor@walesfarmer.co.uk', NULL, NULL, 'Wales Farmer'),
  ('chris.pyke@walesonline.co.uk', 'Chris', 'Pyke', 'WalesOnline'),
  ('sion.barry@walesonline.co.uk', 'Sion', 'Barry', 'WalesOnline'),
  ('catrin.pascoe@walesonline.co.uk', 'Catrin', 'Pascoe', 'WalesOnline'),
  ('david.james@walesonline.co.uk', 'David', 'James', 'WalesOnline'),
  ('steve.adams@westerntelegraph.co.uk', 'Steve', 'Adams', 'Western Telegraph'),
  ('bho@westerntelegraph.co.uk', NULL, NULL, 'Western Telegraph'),
  ('becky.hotchin@westerntelegraph.co.uk', 'Becky', 'Hotchin', 'Western Telegraph'),
  ('bsi@westerntelegraph.co.uk', NULL, NULL, 'Western Telegraph'),
  ('gpt@westerntelegraph.co.uk', NULL, NULL, 'Western Telegraph'),
  ('jhe@westerntelegraph.co.uk', NULL, NULL, 'Western Telegraph'),
  ('rda@westerntelegraph.co.uk', NULL, NULL, 'Western Telegraph');

UPDATE import_contacts i
SET i.is_new = NOT EXISTS (
  SELECT 1 FROM `user` u WHERE i.email IN (u.email, u.email2, u.email3)
);

INSERT INTO `user` (email, roles, password, first_name, last_name, company, created_at, opt_in, created_by_id)
SELECT i.email, '["ROLE_MEMBER"]', '$2y$10$2o5hTemvoLdBkgQ8qvDt5.WuGrkhDW2gt29bgNYtZcTqOC0KrMgVK', i.first_name, i.last_name, i.company, NOW(), 0, @admin
FROM import_contacts i
WHERE i.is_new = 1;

INSERT INTO note (noteable_type, noteable_id, content, added_by_id, created_at)
SELECT 'member', u.id, 'Contact added from press contacts import (PR).', @admin, NOW()
FROM import_contacts i
JOIN `user` u ON u.email = i.email
WHERE i.is_new = 1;

INSERT IGNORE INTO user_tag (user_id, tag_id)
SELECT DISTINCT u.id, @pr_tag
FROM import_contacts i
JOIN `user` u ON i.email IN (u.email, u.email2, u.email3);

-- Sanity check before committing: expect new_contacts + already_on_file = 38.
SELECT SUM(is_new) AS new_contacts, SUM(1 - is_new) AS already_on_file FROM import_contacts;
SELECT COUNT(*) AS tagged_pr FROM user_tag WHERE tag_id = @pr_tag;

COMMIT;

DROP TEMPORARY TABLE import_contacts;
