-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Creato il: Apr 22, 2026 alle 17:58
-- Versione del server: 10.11.14-MariaDB-0+deb12u2
-- Versione PHP: 8.3.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `anpiudine-or1d94_2`
--

-- --------------------------------------------------------

--
-- Struttura della tabella `archivio_storico`
--

CREATE TABLE `archivio_storico` (
  `ID` int(11) DEFAULT NULL,
  `Busta` varchar(255) DEFAULT NULL,
  `Fascicolo` varchar(255) DEFAULT NULL,
  `Serie` varchar(255) DEFAULT NULL,
  `Sottoserie` varchar(255) DEFAULT NULL,
  `Titolo del fascicolo` longtext DEFAULT NULL,
  `Descrizione documento` longtext DEFAULT NULL,
  `Estremi cronologici` varchar(50) DEFAULT NULL,
  `Anno` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `biblio`
--

CREATE TABLE `biblio` (
  `bibid` int(11) NOT NULL,
  `create_dt` datetime NOT NULL,
  `last_change_dt` datetime NOT NULL,
  `last_change_userid` int(11) NOT NULL,
  `material_cd` smallint(6) NOT NULL,
  `collection_cd` smallint(6) NOT NULL,
  `call_nmbr1` varchar(20) DEFAULT NULL,
  `call_nmbr2` varchar(20) DEFAULT NULL,
  `call_nmbr3` varchar(20) DEFAULT NULL,
  `title` text DEFAULT NULL,
  `title_remainder` text DEFAULT NULL,
  `responsibility_stmt` text DEFAULT NULL,
  `author` text DEFAULT NULL,
  `topic1` text DEFAULT NULL,
  `topic2` text DEFAULT NULL,
  `topic3` text DEFAULT NULL,
  `topic4` text DEFAULT NULL,
  `topic5` text DEFAULT NULL,
  `opac_flg` char(1) NOT NULL DEFAULT 'Y',
  `has_cover` char(1) DEFAULT 'N'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `biblio_copy`
--

CREATE TABLE `biblio_copy` (
  `bibid` int(11) NOT NULL,
  `copyid` int(11) NOT NULL,
  `create_dt` datetime NOT NULL,
  `copy_desc` varchar(160) DEFAULT NULL,
  `barcode_nmbr` varchar(20) NOT NULL,
  `status_cd` char(3) NOT NULL,
  `status_begin_dt` datetime NOT NULL,
  `due_back_dt` date DEFAULT NULL,
  `mbrid` int(11) DEFAULT NULL,
  `renewal_count` tinyint(3) UNSIGNED NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `biblio_copy_fields`
--

CREATE TABLE `biblio_copy_fields` (
  `bibid` int(11) NOT NULL,
  `copyid` int(11) NOT NULL,
  `code` varchar(16) NOT NULL,
  `data` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `biblio_copy_fields_dm`
--

CREATE TABLE `biblio_copy_fields_dm` (
  `code` varchar(16) NOT NULL,
  `description` char(32) NOT NULL,
  `default_flg` char(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `biblio_field`
--

CREATE TABLE `biblio_field` (
  `bibid` int(11) NOT NULL,
  `fieldid` int(11) NOT NULL,
  `tag` smallint(6) NOT NULL,
  `ind1_cd` char(1) DEFAULT NULL,
  `ind2_cd` char(1) DEFAULT NULL,
  `subfield_cd` char(1) NOT NULL,
  `field_data` mediumtext DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `biblio_hold`
--

CREATE TABLE `biblio_hold` (
  `bibid` int(11) NOT NULL,
  `copyid` int(11) NOT NULL,
  `holdid` int(11) NOT NULL,
  `hold_begin_dt` datetime NOT NULL,
  `mbrid` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `biblio_index_ext`
--

CREATE TABLE `biblio_index_ext` (
  `bibid` int(11) NOT NULL,
  `isbn` varchar(64) DEFAULT NULL,
  `pub_year` int(4) DEFAULT NULL,
  `publisher` varchar(255) DEFAULT NULL,
  `pub_place` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `biblio_status_dm`
--

CREATE TABLE `biblio_status_dm` (
  `code` char(3) NOT NULL,
  `description` varchar(40) NOT NULL,
  `default_flg` char(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `biblio_status_hist`
--

CREATE TABLE `biblio_status_hist` (
  `bibid` int(11) NOT NULL,
  `copyid` int(11) NOT NULL,
  `status_cd` char(3) NOT NULL,
  `status_begin_dt` datetime NOT NULL,
  `due_back_dt` date DEFAULT NULL,
  `mbrid` int(11) DEFAULT NULL,
  `renewal_count` tinyint(3) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `cdd`
--

CREATE TABLE `cdd` (
  `cdd_Bid` int(11) NOT NULL,
  `cdd_Numero` text DEFAULT NULL,
  `cdd_Descripcion` text DEFAULT NULL,
  `cdd_Clave` text DEFAULT NULL,
  `cdd_Table` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `cdu`
--

CREATE TABLE `cdu` (
  `cdu_Bid` int(11) NOT NULL,
  `cdu_Numero` text DEFAULT NULL,
  `cdu_Descripcion` text DEFAULT NULL,
  `cdu_Clave` text DEFAULT NULL,
  `cdu_Table` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `checkout_privs`
--

CREATE TABLE `checkout_privs` (
  `material_cd` smallint(6) NOT NULL,
  `classification` smallint(6) NOT NULL,
  `checkout_limit` tinyint(3) UNSIGNED NOT NULL,
  `renewal_limit` tinyint(3) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `collection_dm`
--

CREATE TABLE `collection_dm` (
  `code` smallint(6) NOT NULL,
  `description` varchar(40) NOT NULL,
  `default_flg` char(1) NOT NULL,
  `days_due_back` tinyint(3) UNSIGNED NOT NULL,
  `daily_late_fee` decimal(4,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `cover_options`
--

CREATE TABLE `cover_options` (
  `aws_key` varchar(50) DEFAULT NULL,
  `aws_secret_key` varchar(50) DEFAULT NULL,
  `aws_account_id` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `cutter`
--

CREATE TABLE `cutter` (
  `theName` varchar(32) NOT NULL DEFAULT '',
  `theNmbr` mediumint(9) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `email_log`
--

CREATE TABLE `email_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `queue_id` bigint(20) UNSIGNED DEFAULT NULL,
  `to_email` varchar(190) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `template` varchar(190) NOT NULL,
  `status` enum('sent','failed') NOT NULL,
  `error_msg` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `email_queue`
--

CREATE TABLE `email_queue` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `to_email` varchar(190) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `template` varchar(190) NOT NULL,
  `data_json` mediumtext NOT NULL,
  `priority` tinyint(4) NOT NULL DEFAULT 5,
  `status` enum('queued','sending','sent','failed','dead') NOT NULL DEFAULT 'queued',
  `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `last_error` text DEFAULT NULL,
  `scheduled_at` datetime NOT NULL DEFAULT current_timestamp(),
  `locked_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `ibic`
--

CREATE TABLE `ibic` (
  `ibic_Bid` int(11) NOT NULL,
  `ibic_Numero` text DEFAULT NULL,
  `ibic_Descripcion` text DEFAULT NULL,
  `ibic_Clave` text DEFAULT NULL,
  `ibic_Table` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `lookup_hosts`
--

CREATE TABLE `lookup_hosts` (
  `id` int(10) UNSIGNED NOT NULL,
  `seq` tinyint(4) NOT NULL,
  `active` enum('y','n') NOT NULL DEFAULT 'n',
  `host` varchar(50) NOT NULL,
  `name` varchar(50) NOT NULL,
  `db` varchar(20) NOT NULL,
  `user` varchar(20) DEFAULT NULL,
  `pw` varchar(20) DEFAULT NULL,
  `context` varchar(20) DEFAULT 'dc',
  `schema` varchar(20) DEFAULT 'marcxml'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `lookup_manual`
--

CREATE TABLE `lookup_manual` (
  `qmid` int(11) NOT NULL,
  `isbn` varchar(10) NOT NULL,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `hits` tinyint(4) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `lookup_queue`
--

CREATE TABLE `lookup_queue` (
  `qid` int(11) NOT NULL,
  `isbn` varchar(10) NOT NULL,
  `status` enum('queue','manual','publish','copy','cover') NOT NULL DEFAULT 'queue',
  `updated` timestamp NOT NULL DEFAULT current_timestamp(),
  `tries` tinyint(4) NOT NULL DEFAULT 0,
  `amount` smallint(6) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `lookup_settings`
--

CREATE TABLE `lookup_settings` (
  `protocol` enum('YAZ','SRU') NOT NULL DEFAULT 'YAZ',
  `max_hits` tinyint(4) NOT NULL DEFAULT 25,
  `keep_dashes` enum('y','n') NOT NULL DEFAULT 'n',
  `callNmbr_type` enum('LoC','Dew','UDC','local') NOT NULL DEFAULT 'Dew',
  `auto_dewey` enum('y','n') NOT NULL DEFAULT 'y',
  `default_dewey` varchar(10) NOT NULL DEFAULT '813.52',
  `auto_cutter` enum('y','n') NOT NULL DEFAULT 'y',
  `cutter_type` enum('LoC','CS3') NOT NULL DEFAULT 'CS3',
  `cutter_word` tinyint(4) NOT NULL DEFAULT 1,
  `auto_collect` enum('y','n') NOT NULL DEFAULT 'y',
  `fiction_name` varchar(10) NOT NULL DEFAULT 'Fiction',
  `fiction_code` tinyint(4) NOT NULL DEFAULT 1,
  `fiction_loc` varchar(255) NOT NULL DEFAULT 'PQ PR PS PT PU PV PW PX PY PZ',
  `fiction_dewey` varchar(255) NOT NULL DEFAULT '813 823'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `material_type_dm`
--

CREATE TABLE `material_type_dm` (
  `code` smallint(6) NOT NULL,
  `description` varchar(40) NOT NULL,
  `default_flg` char(1) NOT NULL,
  `image_file` varchar(128) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `material_usmarc_xref`
--

CREATE TABLE `material_usmarc_xref` (
  `xref_id` int(11) NOT NULL,
  `materialCd` int(11) NOT NULL DEFAULT 0,
  `tag` char(3) NOT NULL DEFAULT '',
  `subfieldCd` char(1) NOT NULL DEFAULT '',
  `descr` varchar(64) NOT NULL DEFAULT '',
  `required` char(1) NOT NULL DEFAULT '',
  `cntrltype` char(1) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `mbr_classify_dm`
--

CREATE TABLE `mbr_classify_dm` (
  `code` smallint(6) NOT NULL,
  `description` varchar(40) NOT NULL,
  `default_flg` char(1) NOT NULL,
  `max_fines` decimal(4,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `member`
--

CREATE TABLE `member` (
  `mbrid` int(11) NOT NULL,
  `barcode_nmbr` varchar(20) NOT NULL,
  `create_dt` datetime NOT NULL,
  `last_change_dt` datetime NOT NULL,
  `last_change_userid` int(11) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `address` text DEFAULT NULL,
  `home_phone` varchar(15) DEFAULT NULL,
  `work_phone` varchar(15) DEFAULT NULL,
  `cel` varchar(15) DEFAULT NULL,
  `email` varchar(128) DEFAULT NULL,
  `codice_fiscale` varchar(16) DEFAULT NULL,
  `indirizzo` varchar(150) DEFAULT NULL,
  `civico` varchar(10) DEFAULT NULL,
  `cap` varchar(5) DEFAULT NULL,
  `citta` varchar(100) DEFAULT NULL,
  `provincia` char(2) DEFAULT NULL,
  `foto` varchar(128) DEFAULT NULL,
  `pass_user` char(32) DEFAULT NULL,
  `born_dt` date NOT NULL,
  `other` text DEFAULT NULL,
  `classification` smallint(6) NOT NULL,
  `is_active` char(1) DEFAULT 'Y',
  `last_activity_dt` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `member_account`
--

CREATE TABLE `member_account` (
  `mbrid` int(11) NOT NULL,
  `transid` int(11) NOT NULL,
  `create_dt` datetime NOT NULL,
  `create_userid` int(11) NOT NULL,
  `transaction_type_cd` char(2) NOT NULL,
  `amount` decimal(8,2) NOT NULL,
  `description` varchar(128) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `member_fields`
--

CREATE TABLE `member_fields` (
  `mbrid` int(11) NOT NULL,
  `code` varchar(16) NOT NULL,
  `data` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `member_fields_dm`
--

CREATE TABLE `member_fields_dm` (
  `code` varchar(16) NOT NULL,
  `description` char(32) NOT NULL,
  `default_flg` char(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `patron_auth`
--

CREATE TABLE `patron_auth` (
  `id` int(10) UNSIGNED NOT NULL,
  `mbrid` int(10) NOT NULL,
  `email` varchar(190) NOT NULL,
  `pass_hash` varchar(255) NOT NULL,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `session`
--

CREATE TABLE `session` (
  `userid` int(5) NOT NULL,
  `last_updated_dt` datetime NOT NULL,
  `token` int(5) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `settings`
--

CREATE TABLE `settings` (
  `library_name` varchar(128) DEFAULT NULL,
  `library_image_url` text DEFAULT NULL,
  `use_image_flg` char(1) NOT NULL,
  `library_hours` varchar(128) DEFAULT NULL,
  `library_aders` varchar(70) DEFAULT NULL,
  `library_phone` varchar(40) DEFAULT NULL,
  `library_url` text DEFAULT NULL,
  `opac_url` text DEFAULT NULL,
  `session_timeout` smallint(6) NOT NULL,
  `items_per_page` tinyint(4) NOT NULL,
  `version` varchar(10) NOT NULL,
  `themeid` smallint(6) NOT NULL,
  `purge_history_after_months` smallint(6) NOT NULL,
  `block_checkouts_when_fines_due` char(1) NOT NULL,
  `hold_max_days` smallint(6) NOT NULL,
  `locale` varchar(8) NOT NULL,
  `charset` varchar(20) DEFAULT NULL,
  `html_lang_attr` varchar(8) DEFAULT NULL,
  `font_normal` varchar(20) DEFAULT NULL,
  `font_size` tinyint(3) DEFAULT NULL,
  `inactive_member_after_days` smallint(6) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `staff`
--

CREATE TABLE `staff` (
  `userid` int(11) NOT NULL,
  `create_dt` datetime NOT NULL,
  `last_change_dt` datetime NOT NULL,
  `last_change_userid` int(11) NOT NULL,
  `username` varchar(20) NOT NULL,
  `email` varchar(191) DEFAULT NULL,
  `pwd` char(32) NOT NULL,
  `last_name` varchar(30) NOT NULL,
  `first_name` varchar(30) DEFAULT NULL,
  `suspended_flg` char(1) NOT NULL,
  `admin_flg` char(1) NOT NULL,
  `circ_flg` char(1) NOT NULL,
  `circ_mbr_flg` char(1) NOT NULL,
  `catalog_flg` char(1) NOT NULL,
  `reports_flg` char(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `staff_password_reset`
--

CREATE TABLE `staff_password_reset` (
  `id` int(11) NOT NULL,
  `userid` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `theme`
--

CREATE TABLE `theme` (
  `themeid` smallint(6) NOT NULL,
  `theme_name` varchar(40) NOT NULL,
  `title_bg` varchar(20) NOT NULL,
  `title_font_face` varchar(128) NOT NULL,
  `title_font_size` tinyint(4) NOT NULL,
  `title_font_bold` char(1) NOT NULL,
  `title_font_color` varchar(20) NOT NULL,
  `title_align` varchar(30) NOT NULL,
  `primary_bg` varchar(20) NOT NULL,
  `primary_font_face` varchar(128) NOT NULL,
  `primary_font_size` tinyint(4) NOT NULL,
  `primary_font_color` varchar(20) NOT NULL,
  `primary_link_color` varchar(20) NOT NULL,
  `primary_error_color` varchar(20) NOT NULL,
  `alt1_bg` varchar(20) NOT NULL,
  `alt1_font_face` varchar(128) NOT NULL,
  `alt1_font_size` tinyint(4) NOT NULL,
  `alt1_font_color` varchar(20) NOT NULL,
  `alt1_link_color` varchar(20) NOT NULL,
  `alt2_bg` varchar(20) NOT NULL,
  `alt2_font_face` varchar(128) NOT NULL,
  `alt2_font_size` tinyint(4) NOT NULL,
  `alt2_font_color` varchar(20) NOT NULL,
  `alt2_link_color` varchar(20) NOT NULL,
  `alt2_font_bold` char(1) NOT NULL,
  `border_color` varchar(20) NOT NULL,
  `border_width` tinyint(4) NOT NULL,
  `table_padding` tinyint(4) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `transaction_type_dm`
--

CREATE TABLE `transaction_type_dm` (
  `code` char(2) NOT NULL,
  `description` varchar(40) NOT NULL,
  `default_flg` char(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `usmarc_block_dm`
--

CREATE TABLE `usmarc_block_dm` (
  `block_nmbr` tinyint(4) NOT NULL,
  `description` varchar(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `usmarc_indicator_dm`
--

CREATE TABLE `usmarc_indicator_dm` (
  `tag` smallint(6) NOT NULL,
  `indicator_nmbr` tinyint(4) NOT NULL,
  `indicator_cd` char(1) NOT NULL,
  `description` varchar(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `usmarc_subfield_dm`
--

CREATE TABLE `usmarc_subfield_dm` (
  `tag` smallint(6) NOT NULL,
  `subfield_cd` char(1) NOT NULL,
  `description` varchar(80) NOT NULL,
  `repeatable_flg` char(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `usmarc_tag_dm`
--

CREATE TABLE `usmarc_tag_dm` (
  `block_nmbr` tinyint(4) NOT NULL,
  `tag` smallint(6) NOT NULL,
  `description` varchar(80) NOT NULL,
  `ind1_description` varchar(80) NOT NULL,
  `ind2_description` varchar(80) NOT NULL,
  `repeatable_flg` char(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Indici per le tabelle scaricate
--

--
-- Indici per le tabelle `biblio`
--
ALTER TABLE `biblio`
  ADD PRIMARY KEY (`bibid`),
  ADD KEY `idx_biblio_mat` (`material_cd`),
  ADD KEY `idx_biblio_col` (`collection_cd`);
ALTER TABLE `biblio` ADD FULLTEXT KEY `call_nmbr3` (`call_nmbr3`);
ALTER TABLE `biblio` ADD FULLTEXT KEY `ft_title` (`title`,`title_remainder`);
ALTER TABLE `biblio` ADD FULLTEXT KEY `ft_author` (`author`);
ALTER TABLE `biblio` ADD FULLTEXT KEY `ft_subjects` (`topic1`,`topic2`,`topic3`,`topic4`,`topic5`);

--
-- Indici per le tabelle `biblio_copy`
--
ALTER TABLE `biblio_copy`
  ADD PRIMARY KEY (`bibid`,`copyid`),
  ADD UNIQUE KEY `barcode_index` (`barcode_nmbr`),
  ADD KEY `mbr_index` (`mbrid`),
  ADD KEY `idx_copy_bibid_status` (`bibid`,`status_cd`);

--
-- Indici per le tabelle `biblio_copy_fields`
--
ALTER TABLE `biblio_copy_fields`
  ADD PRIMARY KEY (`bibid`,`copyid`,`code`),
  ADD KEY `code_index` (`code`);

--
-- Indici per le tabelle `biblio_copy_fields_dm`
--
ALTER TABLE `biblio_copy_fields_dm`
  ADD PRIMARY KEY (`code`);

--
-- Indici per le tabelle `biblio_field`
--
ALTER TABLE `biblio_field`
  ADD PRIMARY KEY (`bibid`,`fieldid`),
  ADD KEY `idx_bf_bibid` (`bibid`),
  ADD KEY `idx_bf_tag_sub` (`tag`,`subfield_cd`),
  ADD KEY `idx_bf_tag_sub_bibid` (`tag`,`subfield_cd`,`bibid`);

--
-- Indici per le tabelle `biblio_hold`
--
ALTER TABLE `biblio_hold`
  ADD PRIMARY KEY (`bibid`,`copyid`,`holdid`),
  ADD KEY `mbr_index` (`mbrid`);

--
-- Indici per le tabelle `biblio_index_ext`
--
ALTER TABLE `biblio_index_ext`
  ADD PRIMARY KEY (`bibid`),
  ADD KEY `idx_pub_year` (`pub_year`),
  ADD KEY `idx_publisher` (`publisher`),
  ADD KEY `idx_isbn` (`isbn`);

--
-- Indici per le tabelle `biblio_status_dm`
--
ALTER TABLE `biblio_status_dm`
  ADD PRIMARY KEY (`code`);

--
-- Indici per le tabelle `biblio_status_hist`
--
ALTER TABLE `biblio_status_hist`
  ADD KEY `mbr_index` (`mbrid`),
  ADD KEY `copy_index` (`bibid`,`copyid`);

--
-- Indici per le tabelle `cdd`
--
ALTER TABLE `cdd`
  ADD PRIMARY KEY (`cdd_Bid`);

--
-- Indici per le tabelle `cdu`
--
ALTER TABLE `cdu`
  ADD PRIMARY KEY (`cdu_Bid`);

--
-- Indici per le tabelle `checkout_privs`
--
ALTER TABLE `checkout_privs`
  ADD PRIMARY KEY (`material_cd`,`classification`);

--
-- Indici per le tabelle `collection_dm`
--
ALTER TABLE `collection_dm`
  ADD PRIMARY KEY (`code`),
  ADD KEY `idx_collection_desc` (`description`);

--
-- Indici per le tabelle `cutter`
--
ALTER TABLE `cutter`
  ADD PRIMARY KEY (`theName`);

--
-- Indici per le tabelle `email_log`
--
ALTER TABLE `email_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_queue` (`queue_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_to_email` (`to_email`);

--
-- Indici per le tabelle `email_queue`
--
ALTER TABLE `email_queue`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status_sched` (`status`,`scheduled_at`),
  ADD KEY `idx_locked` (`locked_at`),
  ADD KEY `idx_to_email` (`to_email`);

--
-- Indici per le tabelle `ibic`
--
ALTER TABLE `ibic`
  ADD PRIMARY KEY (`ibic_Bid`);

--
-- Indici per le tabelle `lookup_hosts`
--
ALTER TABLE `lookup_hosts`
  ADD PRIMARY KEY (`id`);

--
-- Indici per le tabelle `lookup_manual`
--
ALTER TABLE `lookup_manual`
  ADD PRIMARY KEY (`qmid`);

--
-- Indici per le tabelle `lookup_queue`
--
ALTER TABLE `lookup_queue`
  ADD PRIMARY KEY (`qid`);

--
-- Indici per le tabelle `material_type_dm`
--
ALTER TABLE `material_type_dm`
  ADD PRIMARY KEY (`code`),
  ADD KEY `idx_material_desc` (`description`);

--
-- Indici per le tabelle `material_usmarc_xref`
--
ALTER TABLE `material_usmarc_xref`
  ADD PRIMARY KEY (`xref_id`);

--
-- Indici per le tabelle `mbr_classify_dm`
--
ALTER TABLE `mbr_classify_dm`
  ADD PRIMARY KEY (`code`);

--
-- Indici per le tabelle `member`
--
ALTER TABLE `member`
  ADD PRIMARY KEY (`mbrid`);

--
-- Indici per le tabelle `member_account`
--
ALTER TABLE `member_account`
  ADD PRIMARY KEY (`mbrid`,`transid`);

--
-- Indici per le tabelle `member_fields`
--
ALTER TABLE `member_fields`
  ADD PRIMARY KEY (`mbrid`,`code`),
  ADD KEY `code_index` (`code`);

--
-- Indici per le tabelle `member_fields_dm`
--
ALTER TABLE `member_fields_dm`
  ADD PRIMARY KEY (`code`);

--
-- Indici per le tabelle `patron_auth`
--
ALTER TABLE `patron_auth`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_patron_auth_email` (`email`),
  ADD UNIQUE KEY `uq_patron_auth_mbrid` (`mbrid`),
  ADD KEY `idx_patron_auth_reset` (`reset_token`);

--
-- Indici per le tabelle `staff`
--
ALTER TABLE `staff`
  ADD PRIMARY KEY (`userid`);

--
-- Indici per le tabelle `staff_password_reset`
--
ALTER TABLE `staff_password_reset`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_token_hash` (`token_hash`),
  ADD KEY `idx_expires_used` (`expires_at`,`used`),
  ADD KEY `fk_staff_reset_staff` (`userid`);

--
-- Indici per le tabelle `theme`
--
ALTER TABLE `theme`
  ADD PRIMARY KEY (`themeid`);

--
-- Indici per le tabelle `transaction_type_dm`
--
ALTER TABLE `transaction_type_dm`
  ADD PRIMARY KEY (`code`);

--
-- Indici per le tabelle `usmarc_block_dm`
--
ALTER TABLE `usmarc_block_dm`
  ADD PRIMARY KEY (`block_nmbr`);

--
-- Indici per le tabelle `usmarc_indicator_dm`
--
ALTER TABLE `usmarc_indicator_dm`
  ADD PRIMARY KEY (`tag`,`indicator_nmbr`,`indicator_cd`);

--
-- Indici per le tabelle `usmarc_subfield_dm`
--
ALTER TABLE `usmarc_subfield_dm`
  ADD PRIMARY KEY (`tag`,`subfield_cd`);

--
-- Indici per le tabelle `usmarc_tag_dm`
--
ALTER TABLE `usmarc_tag_dm`
  ADD PRIMARY KEY (`block_nmbr`,`tag`);

--
-- AUTO_INCREMENT per le tabelle scaricate
--

--
-- AUTO_INCREMENT per la tabella `biblio`
--
ALTER TABLE `biblio`
  MODIFY `bibid` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `biblio_copy`
--
ALTER TABLE `biblio_copy`
  MODIFY `copyid` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `biblio_field`
--
ALTER TABLE `biblio_field`
  MODIFY `fieldid` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `biblio_hold`
--
ALTER TABLE `biblio_hold`
  MODIFY `holdid` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cdd`
--
ALTER TABLE `cdd`
  MODIFY `cdd_Bid` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cdu`
--
ALTER TABLE `cdu`
  MODIFY `cdu_Bid` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `collection_dm`
--
ALTER TABLE `collection_dm`
  MODIFY `code` smallint(6) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `email_log`
--
ALTER TABLE `email_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `email_queue`
--
ALTER TABLE `email_queue`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `ibic`
--
ALTER TABLE `ibic`
  MODIFY `ibic_Bid` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `lookup_hosts`
--
ALTER TABLE `lookup_hosts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `lookup_manual`
--
ALTER TABLE `lookup_manual`
  MODIFY `qmid` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `lookup_queue`
--
ALTER TABLE `lookup_queue`
  MODIFY `qid` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `material_type_dm`
--
ALTER TABLE `material_type_dm`
  MODIFY `code` smallint(6) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `material_usmarc_xref`
--
ALTER TABLE `material_usmarc_xref`
  MODIFY `xref_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `mbr_classify_dm`
--
ALTER TABLE `mbr_classify_dm`
  MODIFY `code` smallint(6) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `member`
--
ALTER TABLE `member`
  MODIFY `mbrid` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `member_account`
--
ALTER TABLE `member_account`
  MODIFY `transid` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `patron_auth`
--
ALTER TABLE `patron_auth`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `staff`
--
ALTER TABLE `staff`
  MODIFY `userid` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `staff_password_reset`
--
ALTER TABLE `staff_password_reset`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `theme`
--
ALTER TABLE `theme`
  MODIFY `themeid` smallint(6) NOT NULL AUTO_INCREMENT;

--
-- Limiti per le tabelle scaricate
--

--
-- Limiti per la tabella `staff_password_reset`
--
ALTER TABLE `staff_password_reset`
  ADD CONSTRAINT `fk_staff_reset_staff` FOREIGN KEY (`userid`) REFERENCES `staff` (`userid`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
