CREATE TABLE IF NOT EXISTS `iiif_manifest` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `manifest_id` VARCHAR(255) NOT NULL,
  `data` LONGTEST NOT NULL,
  PRIMARY KEY (`id`)
);

CREATE TABLE IF NOT EXISTS `datahub_data` (
  `id` VARCHAR(255) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `value` text NOT NULL,
  PRIMARY KEY (`id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `resource_data` (
  `id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `value` MEDIUMTEXT NOT NULL,
  PRIMARY KEY(`id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
