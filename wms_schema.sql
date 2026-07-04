CREATE TABLE `acceptance` (
  `id` int(11) NOT NULL,
  `inventory_order_id` text COLLATE utf8_unicode_ci,
  `estate_name` text COLLATE utf8_unicode_ci,
  `location` text COLLATE utf8_unicode_ci,
  `olt_name` text COLLATE utf8_unicode_ci,
  `cabinet_number` text COLLATE utf8_unicode_ci,
  `homes_passed_design` text COLLATE utf8_unicode_ci,
  `homes_passed_ground` text COLLATE utf8_unicode_ci,
  `homes_passed_comments` text COLLATE utf8_unicode_ci,
  `closures_design` text COLLATE utf8_unicode_ci,
  `closures_ground` text COLLATE utf8_unicode_ci,
  `closures_comments` text COLLATE utf8_unicode_ci,
  `splitters_design` text COLLATE utf8_unicode_ci,
  `splitters_ground` text COLLATE utf8_unicode_ci,
  `splitters_comments` text COLLATE utf8_unicode_ci,
  `cabinets_design` text COLLATE utf8_unicode_ci,
  `cabinets_ground` text COLLATE utf8_unicode_ci,
  `cabinets_comments` text COLLATE utf8_unicode_ci,
  `osp_distance_design` text COLLATE utf8_unicode_ci,
  `osp_distance_ground` text COLLATE utf8_unicode_ci,
  `osp_distance_comments` text COLLATE utf8_unicode_ci,
  `fats_design` text COLLATE utf8_unicode_ci,
  `fats_ground` text COLLATE utf8_unicode_ci,
  `fats_comments` text COLLATE utf8_unicode_ci,
  `manholes_precast` text COLLATE utf8_unicode_ci,
  `manholes_precast_ground` text COLLATE utf8_unicode_ci,
  `manholes_precast_comments` text COLLATE utf8_unicode_ci,
  `manholes_masonry` text COLLATE utf8_unicode_ci,
  `manholes_masonry_ground` text COLLATE utf8_unicode_ci,
  `manholes_masonry_comments` text COLLATE utf8_unicode_ci,
  `handholes` text COLLATE utf8_unicode_ci,
  `handholes_ground` text COLLATE utf8_unicode_ci,
  `handholes_comments` text COLLATE utf8_unicode_ci,
  `trench_12` text COLLATE utf8_unicode_ci,
  `trench_12_ground` text COLLATE utf8_unicode_ci,
  `trench_12_comments` text COLLATE utf8_unicode_ci,
  `trench_06` text COLLATE utf8_unicode_ci,
  `trench_06_ground` text COLLATE utf8_unicode_ci,
  `trench_06_comments` text COLLATE utf8_unicode_ci,
  `num_poles` text COLLATE utf8_unicode_ci,
  `num_poles_ground` text COLLATE utf8_unicode_ci,
  `num_poles_comments` text COLLATE utf8_unicode_ci,
  `cable_stays` text COLLATE utf8_unicode_ci,
  `cable_stays_ground` text COLLATE utf8_unicode_ci,
  `cable_stays_comments` text COLLATE utf8_unicode_ci,
  `cable_brackets` text COLLATE utf8_unicode_ci,
  `cable_brackets_ground` text COLLATE utf8_unicode_ci,
  `cable_brackets_comments` text COLLATE utf8_unicode_ci,
  `network_status` text COLLATE utf8_unicode_ci,
  `network_status_comments` text COLLATE utf8_unicode_ci,
  `reinstatement_state` text COLLATE utf8_unicode_ci,
  `reinstatement` text COLLATE utf8_unicode_ci,
  `slack_management_state` text COLLATE utf8_unicode_ci,
  `slack_management` text COLLATE utf8_unicode_ci,
  `manhole_condition_state` text COLLATE utf8_unicode_ci,
  `manhole_condition` text COLLATE utf8_unicode_ci,
  `cable_marker` text COLLATE utf8_unicode_ci,
  `cable_marker_ground` text COLLATE utf8_unicode_ci,
  `cable_marker_comments` text COLLATE utf8_unicode_ci,
  `flex_cable_m` text COLLATE utf8_unicode_ci,
  `flex_cable_m_ground` text COLLATE utf8_unicode_ci,
  `flex_cable_m_comments` text COLLATE utf8_unicode_ci,
  `pvc_conduit_m` text COLLATE utf8_unicode_ci,
  `pvc_conduit_m_ground` text COLLATE utf8_unicode_ci,
  `pvc_conduit_m_comments` text COLLATE utf8_unicode_ci,
  `plastic_trunking_m` text COLLATE utf8_unicode_ci,
  `plastic_trunking_m_ground` text COLLATE utf8_unicode_ci,
  `plastic_trunking_m_comments` text COLLATE utf8_unicode_ci,
  `duct_32mm_m` text COLLATE utf8_unicode_ci,
  `duct_32mm_m_ground` text COLLATE utf8_unicode_ci,
  `duct_32mm_m_comments` text COLLATE utf8_unicode_ci,
  `tension_clamps` text COLLATE utf8_unicode_ci,
  `tension_clamps_ground` text COLLATE utf8_unicode_ci,
  `tension_clamps_comments` text COLLATE utf8_unicode_ci,
  `tangent_support` text COLLATE utf8_unicode_ci,
  `tangent_support_ground` text COLLATE utf8_unicode_ci,
  `tangent_support_comments` text COLLATE utf8_unicode_ci,
  `j_hooks` text COLLATE utf8_unicode_ci,
  `j_hooks_ground` text COLLATE utf8_unicode_ci,
  `j_hooks_comments` text COLLATE utf8_unicode_ci,
  `down_lead_clamps` text COLLATE utf8_unicode_ci,
  `down_lead_clamps_ground` text COLLATE utf8_unicode_ci,
  `down_lead_clamps_comments` text COLLATE utf8_unicode_ci,
  `adjustable_brackets` text COLLATE utf8_unicode_ci,
  `adjustable_brackets_ground` text COLLATE utf8_unicode_ci,
  `adjustable_brackets_comments` text COLLATE utf8_unicode_ci,
  `universal_pole_bracket_upb` text COLLATE utf8_unicode_ci,
  `universal_pole_bracket_upb_ground` text COLLATE utf8_unicode_ci,
  `universal_pole_bracket_upb_comments` text COLLATE utf8_unicode_ci,
  `cabinet_cable_management_state` text COLLATE utf8_unicode_ci,
  `cabinet_cable_management` text COLLATE utf8_unicode_ci,
  `cabinet_labelling_state` text COLLATE utf8_unicode_ci,
  `cabinet_labelling` text COLLATE utf8_unicode_ci,
  `cabinet_power_levels_state` text COLLATE utf8_unicode_ci,
  `cabinet_power_levels` text COLLATE utf8_unicode_ci,
  `closures_cable_management_state` text COLLATE utf8_unicode_ci,
  `closures_cable_management` text COLLATE utf8_unicode_ci,
  `closures_labelling_state` text COLLATE utf8_unicode_ci,
  `closures_labelling` text COLLATE utf8_unicode_ci,
  `closures_splices_state` text COLLATE utf8_unicode_ci,
  `closures_splices` text COLLATE utf8_unicode_ci,
  `fat_cable_management_state` text COLLATE utf8_unicode_ci,
  `fat_cable_management` text COLLATE utf8_unicode_ci,
  `fat_labelling_state` text COLLATE utf8_unicode_ci,
  `fat_labelling` text COLLATE utf8_unicode_ci,
  `fat_power_levels_state` text COLLATE utf8_unicode_ci,
  `fat_power_levels` text COLLATE utf8_unicode_ci,
  `fat_splices_state` text COLLATE utf8_unicode_ci,
  `fat_splices` text COLLATE utf8_unicode_ci,
  `accepted_by` text COLLATE utf8_unicode_ci,
  `accepted_on` text COLLATE utf8_unicode_ci,
  `signature` text COLLATE utf8_unicode_ci,
  `status` text COLLATE utf8_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `map_url` text COLLATE utf8_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE `acceptance_photos` (
  `record_id` int(11) NOT NULL,
  `file_path` text COLLATE utf8_unicode_ci NOT NULL,
  `file_name` text COLLATE utf8_unicode_ci NOT NULL,
  `type` text COLLATE utf8_unicode_ci NOT NULL,
  `date` text COLLATE utf8_unicode_ci NOT NULL,
  `acceptance_id` text COLLATE utf8_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE `acceptance_sign` (
  `id` int(11) NOT NULL,
  `aby` text COLLATE utf8_unicode_ci NOT NULL,
  `signature` text COLLATE utf8_unicode_ci NOT NULL,
  `date` text COLLATE utf8_unicode_ci NOT NULL,
  `acceptance_id` text COLLATE utf8_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE `barcodes` (
  `bar_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `barcodes` text NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `role` text,
  `pid` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `brand` (
  `brand_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `brand_name` varchar(250) NOT NULL,
  `brand_status` enum('active','inactive') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `category` (
  `category_id` int(11) NOT NULL,
  `category_name` varchar(250) NOT NULL,
  `category_status` enum('active','inactive') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `customer` (
  `id` int(11) NOT NULL,
  `full_name` text NOT NULL,
  `email` text NOT NULL,
  `phone` text NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

CREATE TABLE `euser_details` (
  `user_id` int(11) NOT NULL,
  `user_email` text NOT NULL,
  `user_password` text NOT NULL,
  `user_name` text NOT NULL,
  `user_team` text,
  `user_type` text NOT NULL,
  `user_status` text NOT NULL,
  `user_location` varchar(255) NOT NULL DEFAULT 'Nairobi',
  `department` text NOT NULL,
  `agent_id` text NOT NULL,
  `user_extra` text NOT NULL,
  `face_descriptor` text,
  `face_login_enabled` tinyint(1) DEFAULT '0',
  `face_updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `inventory_order` (
  `inventory_order_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `inventory_order_total` double(10,2) NOT NULL,
  `inventory_order_date` date NOT NULL,
  `inventory_order_name` varchar(255) NOT NULL,
  `inventory_order_address` text NOT NULL,
  `payment_status` enum('cash','credit') NOT NULL,
  `inventory_order_status` varchar(100) NOT NULL,
  `inventory_order_created_date` date NOT NULL,
  `team` varchar(255) NOT NULL,
  `rid` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `inventory_order_product` (
  `inventory_order_product_id` int(11) NOT NULL,
  `inventory_order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `price` double(10,2) NOT NULL,
  `tax` double(10,2) NOT NULL,
  `serial_no` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `locations` (
  `location_id` int(11) NOT NULL,
  `location_name` text NOT NULL,
  `location_status` varchar(255) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `product` (
  `product_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `brand_id` text NOT NULL,
  `product_name` varchar(300) NOT NULL,
  `product_description` text NOT NULL,
  `product_quantity` int(11) NOT NULL,
  `product_unit` varchar(150) NOT NULL,
  `product_base_price` double(10,2) NOT NULL,
  `product_tax` decimal(4,2) NOT NULL,
  `product_minimum_order` double(10,2) NOT NULL,
  `product_enter_by` int(11) NOT NULL,
  `product_status` enum('active','inactive') NOT NULL,
  `product_date` date NOT NULL,
  `product_type` varchar(255) NOT NULL DEFAULT 'noscan',
  `upc` text NOT NULL,
  `tech` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `pwdreset` (
  `id` int(11) NOT NULL,
  `email` text NOT NULL,
  `code` text NOT NULL,
  `status` text NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `receive` (
  `receive_id` varchar(255) NOT NULL,
  `warehouse` text NOT NULL,
  `products` text NOT NULL,
  `receive_by` text NOT NULL,
  `receive_from` text NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `receive_barcodes` (
  `id` int(11) NOT NULL,
  `receive_id` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `pid` int(11) NOT NULL,
  `barcodes` longtext COLLATE utf8_unicode_ci,
  `role` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `scan_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `scanned_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE `receive_files` (
  `id` int(11) NOT NULL,
  `receive_id` text NOT NULL,
  `file_path` text NOT NULL,
  `file_name` text NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `recoveries` (
  `recovery_id` int(11) NOT NULL,
  `rec_account` text NOT NULL,
  `rec_use_id` text NOT NULL,
  `product_id` text NOT NULL,
  `product_condition` text NOT NULL,
  `rec_wo` text NOT NULL,
  `product_code` text NOT NULL,
  `recovery_date` datetime NOT NULL,
  `comment` text NOT NULL,
  `use_date` datetime NOT NULL,
  `user_id` text NOT NULL,
  `rec_user` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `regions` (
  `region_id` int(11) NOT NULL,
  `region_name` text NOT NULL,
  `region_location` varchar(255) NOT NULL DEFAULT 'Nairobi',
  `status` varchar(255) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `requisition` (
  `rid` int(11) NOT NULL,
  `tid` text NOT NULL,
  `rby` text NOT NULL,
  `aby` text NOT NULL,
  `iby` text NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'Pending',
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `date_a` datetime NOT NULL,
  `date_i` datetime NOT NULL,
  `comment` text NOT NULL,
  `date_f` datetime DEFAULT NULL,
  `fby` text
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `requisition_product` (
  `rpid` int(11) NOT NULL,
  `rid` int(11) NOT NULL,
  `pid` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `qtyr` int(11) NOT NULL,
  `qtyi` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `site_photos` (
  `file_id` int(11) NOT NULL,
  `recon_id` text NOT NULL,
  `file_path` text NOT NULL,
  `file_name` text NOT NULL,
  `type` text NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `site_visit_activities` (
  `id` int(11) NOT NULL,
  `report_id` int(11) NOT NULL,
  `activity` text COLLATE utf8_unicode_ci,
  `findings` text COLLATE utf8_unicode_ci,
  `photo` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `action_plan` text COLLATE utf8_unicode_ci,
  `recommendation` text COLLATE utf8_unicode_ci,
  `responsible` text COLLATE utf8_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE `site_visit_reports` (
  `id` int(11) NOT NULL,
  `report_date` date NOT NULL,
  `site_visited` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `site_visited_by` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE `stocktake` (
  `stake_id` int(11) NOT NULL,
  `fname` text NOT NULL,
  `user_team` text NOT NULL,
  `comment` text NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'new',
  `date` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `stocktake_product` (
  `spid` int(11) NOT NULL,
  `stake_id` int(11) NOT NULL,
  `pid` int(11) NOT NULL,
  `qty` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `teams` (
  `team_id` int(11) NOT NULL,
  `team_name` varchar(255) NOT NULL,
  `location` varchar(255) NOT NULL,
  `team_region` text NOT NULL,
  `team_type` varchar(255) NOT NULL DEFAULT 'Ordinary',
  `status` varchar(255) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `tinventory_order` (
  `inventory_order_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `inventory_order_total` double(10,2) NOT NULL,
  `inventory_order_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `inventory_order_name` varchar(255) NOT NULL,
  `inventory_order_address` text NOT NULL,
  `payment_status` enum('cash','credit') NOT NULL,
  `inventory_order_status` varchar(100) NOT NULL,
  `inventory_order_created_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `team` varchar(255) NOT NULL,
  `task_id` text NOT NULL,
  `site_location` text NOT NULL,
  `comment` text NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'Pending Approval',
  `wo` text NOT NULL,
  `node` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `tinventory_order_product` (
  `inventory_order_product_id` int(11) NOT NULL,
  `inventory_order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `tinventory_transfer` (
  `transfer_id` int(11) NOT NULL,
  `from_team_id` int(11) NOT NULL,
  `to_team_id` int(11) NOT NULL,
  `transfer_date` datetime NOT NULL,
  `user_id` int(11) NOT NULL,
  `comment` text,
  `status` varchar(50) DEFAULT 'Completed'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `tinventory_transfer_barcode` (
  `transfer_barcode_id` int(11) NOT NULL,
  `transfer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `barcode` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `tinventory_transfer_product` (
  `transfer_product_id` int(11) NOT NULL,
  `transfer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `tproduct` (
  `product_id` int(11) NOT NULL,
  `product_quantity` int(11) NOT NULL,
  `product_team` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `transfer_log` (
  `t_id` int(11) NOT NULL,
  `t_dest` text NOT NULL,
  `t_from` text NOT NULL,
  `t_by` text NOT NULL,
  `t_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `t_items` text NOT NULL,
  `t_status` text NOT NULL,
  `t_iby` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `ttransfers` (
  `transfer_id` int(11) NOT NULL,
  `source_team_id` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `destination_team_id` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `transfer_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `user_id` int(11) NOT NULL,
  `comment` text COLLATE utf8_unicode_ci,
  `status` varchar(50) COLLATE utf8_unicode_ci DEFAULT 'Completed'
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE `usecodes` (
  `bar_id` int(11) NOT NULL,
  `order_no` int(11) NOT NULL,
  `team_id` int(11) NOT NULL,
  `pid` int(11) NOT NULL,
  `barcodes` text NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `user_details` (
  `user_id` int(11) NOT NULL,
  `user_email` varchar(200) NOT NULL,
  `user_password` varchar(200) NOT NULL,
  `user_name` varchar(200) NOT NULL,
  `user_type` text NOT NULL,
  `user_status` text NOT NULL,
  `user_location` varchar(255) NOT NULL DEFAULT 'Nairobi',
  `face_descriptor` text,
  `face_login_enabled` tinyint(1) DEFAULT '0',
  `face_updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `user_typ` (
  `id` int(11) NOT NULL,
  `type` text NOT NULL,
  `name` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `worktype` (
  `deptid` int(11) NOT NULL,
  `dname` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COMMENT='eg. installation';

