-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: localhost
-- Tiempo de generación: 18-05-2026 a las 17:49:26
-- Versión del servidor: 8.0.44
-- Versión de PHP: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `c2881399_sgo`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `articulos`
--

CREATE TABLE `articulos` (
  `id` mediumint UNSIGNED NOT NULL,
  `categoria_id` smallint UNSIGNED NOT NULL,
  `codigo` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `precio_contado` decimal(12,2) NOT NULL DEFAULT '0.00',
  `precio_financiado` decimal(12,2) NOT NULL DEFAULT '0.00',
  `cuotas` tinyint UNSIGNED NOT NULL DEFAULT '1',
  `monto_cuota` decimal(12,2) GENERATED ALWAYS AS (round((`precio_financiado` / `cuotas`),2)) STORED,
  `stock_actual` smallint NOT NULL DEFAULT '0',
  `stock_minimo` smallint NOT NULL DEFAULT '1',
  `imagen_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `articulos`
--

INSERT INTO `articulos` (`id`, `categoria_id`, `codigo`, `nombre`, `descripcion`, `precio_contado`, `precio_financiado`, `cuotas`, `stock_actual`, `stock_minimo`, `imagen_url`, `activo`, `created_at`, `updated_at`) VALUES
(1, 1, NULL, 'Smartv 55\" TCL', '', 1500000.00, 1752000.00, 12, 1, 1, NULL, 0, '2026-03-27 18:34:58', '2026-03-28 15:21:51'),
(2, 1, NULL, 'Smartv 43\"Siera', '', 555.00, 1320000.00, 12, 1, 1, NULL, 0, '2026-03-27 19:07:48', '2026-03-28 15:21:47'),
(3, 1, '186-26875', 'HELADERA DREAN 277L', '', 958000.00, 1695000.00, 12, 1, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/10377/image_1024/%5B186-26875%5D%20HELADERA%20DREAN%20HDR280F50B%20277L?unique=91a8456', 1, '2026-03-28 15:26:22', '2026-05-01 16:25:32'),
(4, 1, '186-37273', 'LAVARROPAS DREAN 7KG', '', 401600.00, 753000.00, 10, 1, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/10684/image_1024/%5B186-37273%5D%20LAVARROPAS%20DREAN%20LTDR79SB%207KG?unique=0c97c5a', 1, '2026-03-28 15:28:49', '2026-04-06 23:19:11'),
(5, 1, '186-01151', 'SECARROPAS DREAN QV 6.5K', '', 312000.00, 585000.00, 10, 2, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/2602/image_1024/%5B186-01151%5D%20SECARROPAS%20DREAN%20QV%206.5K?unique=4f769d5', 1, '2026-03-28 15:30:20', '2026-04-13 21:40:36'),
(6, 1, '186-37846', 'LAVARROPAS DREAN 5KG', '', 248000.00, 465000.00, 8, 0, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/10683/image_1024/%5B186-37846%5D%20LAVARROPAS%20DREAN%20LRDR57SB0%205KG?unique=2be6d58', 1, '2026-03-28 15:32:23', '2026-05-01 16:29:36'),
(7, 1, '045-09270', 'COCINA ESLABON DE LUJO  56CM', '', 859200.00, 1611000.00, 12, 1, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/9674/image_1024/%5B045-09270%5D%20COCINA%20ESLABON%20DE%20LUJO%20EFM56NB2%2056CM?unique=b48d84b', 1, '2026-03-28 15:34:56', '2026-04-23 09:10:23'),
(8, 1, '080-08133', 'CAFETERA ATMA CA-8133 FILTRO', '', 6080000.00, 7600000.00, 3, 2, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/520/image_1024/%5B080-08133%5D%20CAFETERA%20ATMA%20CA-8133%20FILTRO?unique=c121df5', 0, '2026-03-28 15:36:05', '2026-04-01 15:27:34'),
(9, 1, '084-68253', 'PAVA PHILCO  NEGRO C/CORTE', '', 49600.00, 93000.00, 3, 4, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/6348/image_1024/%5B084-68253%5D%20PAVA%20PHILCO%20PE1821NPP%20NEGRO%20C-CORTE?unique=c121df5', 1, '2026-03-28 15:59:56', '2026-04-14 22:25:10'),
(10, 1, '121-81354', 'HORNO ELECTRICO SANSEI 63L', '', 265600.00, 498000.00, 10, 1, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/12751/image_1024/%5B121-81354%5D%20HORNO%20ELECTRICO%20SANSEI%20HGCSA6324AUAPI%2063L%20CONVECCION%20Y%20ANAFES?unique=aa48f98', 1, '2026-03-28 16:03:02', '2026-04-23 09:10:01'),
(11, 6, '017-45726', 'SECADOR GAMA MISTRAL CERAMIC ION 2200W', '', 73600.00, 138000.00, 4, 2, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/12358/image_1024/%5B017-45726%5D%20SECADOR%20GAMA%20MISTRAL%20CERAMIC%20ION%202200W?unique=c121df5', 1, '2026-03-28 16:21:35', '2026-03-28 16:21:35'),
(12, 6, '017-43456', 'COMBO GAMA SECADOR BORA+ALISADOR ELEGANCE MATCHA', '', 140800.00, 264000.00, 6, 1, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/12774/image_1024/%5B017-43456%5D%20COMBO%20GAMA%20SECADOR%20BORA%2BALISADOR%20ELEGANCE%20MATCHA?unique=fc69638', 1, '2026-03-28 16:41:24', '2026-04-06 23:22:58'),
(13, 6, '087-03544', 'TRIMMER WAHL LAUNCH PROFESIONAL INALAMBRICO CUCHILLA T-WIDE', '', 172800.00, 324000.00, 8, 1, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/12283/image_1024/%5B087-03544%5D%20TRIMMER%20WAHL%20LAUNCH%20PROFESIONAL%20INALAMBRICO%20CUCHILLA%20T-WIDE?unique=c121df5', 1, '2026-03-28 16:49:47', '2026-03-28 16:53:23'),
(14, 6, '087-03726', 'TRIMMER WAHL GROOMSMAN SPORT INALAMBRICO PARA BARBA Y PATILLA', '', 78400.00, 147000.00, 4, 1, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/12286/image_1024/%5B087-03726%5D%20TRIMMER%20WAHL%20GROOMSMAN%20SPORT%20INALAMBRICO%20PARA%20BARBA%20Y%20PATILLA?unique=c121df5', 1, '2026-03-28 16:51:09', '2026-03-28 16:51:09'),
(15, 1, '080-67317', 'MULTIPROCESADORA ATMA MP8405P 650W', '', 196800.00, 369000.00, 8, 1, 1, 'https://tucuman.dipromas.com.ar/web/image/product.image/19835/image_1024/MULTIPROCESADORA%20ATMA%20MP8405P%20650W?unique=9444b61', 1, '2026-03-31 14:47:03', '2026-03-31 14:47:03'),
(16, 5, '086-45461', 'BATERIA DE COCINA TRAMONTINA 27899/496 TURIM 12PZS NEGRO', '', 196000.00, 369000.00, 8, 1, 1, 'https://tucuman.dipromas.com.ar/web/image/product.image/29285/image_1024/BATERIA%20DE%20COCINA%20TRAMONTINA%2027899-496%20TURIM%2012PZS%20NEGRO?unique=34195cb', 1, '2026-03-31 14:48:45', '2026-04-01 14:40:34'),
(17, 7, '042-02486', 'VENTILADOR INDELPLAS IVP-24S PIE 24\"', '', 168000.00, 315000.00, 8, 1, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/10546/image_1024/%5B042-02486%5D%20VENTILADOR%20INDELPLAS%20IVP-24S%20PIE%2024%22?unique=c121df5', 1, '2026-03-31 14:53:16', '2026-03-31 14:53:16'),
(18, 1, '080-34273', 'TOSTADORA ATMA TO20BP NEGRO', '', 49600.00, 93000.00, 3, 2, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/571/image_1024/%5B080-34273%5D%20TOSTADORA%20ATMA%20TO20BP%20NEGRO?unique=c121df5', 1, '2026-03-31 15:02:22', '2026-03-31 15:02:22'),
(19, 5, NULL, 'Bicicletas R29', '', 100.00, 93600000.00, 1, 2, 1, 'https://dencar.com.ar/wp-content/uploads/2025/06/2129001-013Q.jpg', 0, '2026-04-01 13:55:07', '2026-04-01 14:57:03'),
(20, 5, NULL, 'Bicicleta R29', '', 700000.00, 936000.00, 8, 3, 1, 'https://dencar.com.ar/wp-content/uploads/2025/06/2129001-013Q.jpg', 0, '2026-04-01 14:57:37', '2026-04-14 15:24:23'),
(21, 2, '6546', 'Ropero 6 Puertas', '', 1.00, 810000.00, 6, 1, 1, 'https://http2.mlstatic.com/D_NQ_NP_2X_850415-MLA95676795866_102025-F.webp', 1, '2026-04-01 15:21:50', '2026-05-14 13:42:06'),
(22, 1, '080-67683', 'MINIPIMER ATMA LM8507AP 600W', '', 52800.00, 93000.00, 3, 1, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/11231/image_1024/%5B080-67683%5D%20MINIPIMER%20ATMA%20LM8507AP%20600W?unique=7e98128', 1, '2026-04-01 15:25:32', '2026-04-01 15:25:32'),
(24, 1, NULL, 'CAFETERA ATMA CA-83 FILTRO', '', 60800.00, 114000.00, 3, 2, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/520/image_1024/%5B080-08133%5D%20CAFETERA%20ATMA%20CA-8133%20FILTRO?unique=c121df5', 1, '2026-04-01 15:31:11', '2026-04-01 15:31:11'),
(25, 2, '1111', 'Alacena y Bajo mesada 120 Grafito', '', 11.00, 480000.00, 4, 1, 1, 'https://http2.mlstatic.com/D_NQ_NP_2X_761568-MLA85158268876_052025-F.webp', 1, '2026-04-01 16:17:58', '2026-04-01 16:17:58'),
(26, 2, NULL, 'Combo Ropero + Somier de 1 1/2', 'Combo Somier completo con respaldo mas ropero 1 1/2', 1.00, 550000.00, 5, 0, 1, 'https://montenegrohogar.com.ar/wp-content/uploads/2022/04/maxiking4caoba.jpg.webp', 1, '2026-04-01 16:20:08', '2026-05-01 16:11:12'),
(27, 2, NULL, 'Alacena y Bajo Mesada 140', '', 1.00, 498000.00, 6, 1, 1, 'https://http2.mlstatic.com/D_NQ_NP_2X_737210-MLA70978598475_082023-F.webp', 1, '2026-04-01 16:23:01', '2026-04-01 16:23:01'),
(28, 2, NULL, 'Ropero Puertas Corredizas', '', 1.00, 870000.00, 10, 1, 1, 'https://http2.mlstatic.com/D_NQ_NP_2X_859986-MLA50864362493_072022-F.webp', 1, '2026-04-01 16:24:39', '2026-04-01 16:24:39'),
(29, 1, '080-38899', 'FREIDORA DE AIRE ATMA 6.5L', '', 228000.00, 426000.00, 6, 2, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/9049/image_1024/%5B080-38899%5D%20FREIDORA%20DE%20AIRE%20ATMA%20FR60ARWP%206.5L?unique=c121df5', 1, '2026-04-01 16:28:24', '2026-04-16 11:54:51'),
(30, 8, NULL, 'Sillones Eco', 'Sillones Eco, Azul, Gris, Negro', 1.00, 504000.00, 8, 2, 1, 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQKzd-zNlFSeQybI1MQqVrarDXuLKSERNHy4k1icG8MHRcD0SAy', 1, '2026-04-01 17:00:26', '2026-04-23 10:09:25'),
(31, 8, NULL, 'Sillon Rollitos', '', 1.00, 1200000.00, 20, 1, 1, 'https://www.mobilar.com.ar/web-experto/blobs/eyJfcmFpbHMiOnsibWVzc2FnZSI6IkJBaHBBZ1Y5IiwiZXhwIjpudWxsLCJwdXIiOiJibG9iX2lkIn19--a4ad69260bbbadca65d649a2c1439c6db214aba3/1742235418-FRONTERA-51232.webp', 1, '2026-04-01 17:02:43', '2026-04-16 11:58:30'),
(32, 7, NULL, 'Aire Split Paioner', '', 100.00, 1848000.00, 12, 1, 1, 'https://http2.mlstatic.com/D_NQ_NP_2X_806279-MLA99558493266_122025-F.webp', 1, '2026-04-01 17:03:55', '2026-04-01 17:04:36'),
(33, 9, NULL, 'Somier King Size', 'Base Colchon y respaldo', 100.00, 1560000.00, 12, 2, 1, 'https://decathogar.com.ar/wp-content/uploads/2025/11/SOMMIER-SUPER-KING-BARI-GRIS-800x800.webp', 1, '2026-04-01 17:07:08', '2026-04-14 22:07:16'),
(34, 7, NULL, 'Aire de Ventana Electra', '', 100.00, 1968000.00, 12, 1, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/12601/image_1024/%5B149-62691%5D%20AIRE%20ELECTRA%20EWF12FC%20VENTANA%203600W?unique=8d819ae', 1, '2026-04-02 17:33:33', '2026-04-23 09:21:35'),
(35, 1, NULL, 'Smartv 55 Tcl', '', 1000000.00, 1800000.00, 12, 0, 1, 'https://encrypted-tbn0.gstatic.com/shopping?q=tbn:ANd9GcQQ3QBWryg1TOosxAk_MNqKHMvpxdRVUE089fwKoSl9yWlvfGakg-GACMc9bCEIEQLRvwiyCogWhafJXLC65_11Gjwz7MeLVc_Li55oy8xg7wGUCSaiXwS2Qg', 1, '2026-04-07 17:44:09', '2026-04-15 14:51:32'),
(36, 1, NULL, 'Smartv Sierra 43', '', 900000.00, 1320000.00, 12, 0, 1, 'https://encrypted-tbn0.gstatic.com/shopping?q=tbn:ANd9GcRggPP6m-xj4sY3OX6FCCZENh5O9x6uP38B30smZkF7T0xr3HDAlpErl99ZgnHMqUo86xcScMhW4QQ48vMly4h6DdKKrNwY', 1, '2026-04-07 17:47:23', '2026-04-18 18:12:49'),
(37, 3, NULL, 'Redmi 15 C', '', 1.00, 750000.00, 10, 1, 1, 'https://http2.mlstatic.com/D_NQ_NP_2X_695025-MLA109689640185_032026-F.webp', 1, '2026-04-10 17:05:56', '2026-04-18 18:35:49'),
(38, 8, NULL, 'Sillon Capitone', '', 485000.00, 89999980.00, 10, 1, 1, 'https://i.pinimg.com/originals/2a/38/e6/2a38e6b24b8f6c2b2b1bb1a16b535b43.jpg', 1, '2026-04-14 22:02:15', '2026-04-16 11:57:39'),
(39, 8, NULL, 'Sillon Pupitos XL Con almohadones', '', 55999999.00, 800000.00, 10, 1, 1, 'https://encrypted-tbn1.gstatic.com/images?q=tbn:ANd9GcS1Hnwl6j84h2KUEVfXHVKJTf-7Hpa3qzc3L3mowawNY3pr5LOB', 1, '2026-04-14 22:05:44', '2026-04-16 11:58:16'),
(40, 2, NULL, 'Rack Completo Color Pinot', '', 1.00, 405000.00, 5, 1, 1, 'https://acdn-us.mitiendanube.com/stores/006/161/293/products/combo-milan-pinot-posta-copia-c6edaee47654f5515317495908132515-640-0.webp', 1, '2026-04-14 22:12:40', '2026-04-15 14:42:47'),
(41, 1, '080-68659', 'LICUADORA ATMA LI8446AP 500W 1.5L JARRA PLASTICA', '', 9999999999.99, 156000.00, 3, 2, 1, 'https://sgo.imperiocomercial.com.ar/assets/uploads/articulos/art_69df8cdc0b4fa4.90093329.jpg', 0, '2026-04-14 22:20:38', '2026-04-15 10:05:21'),
(42, 2, NULL, 'Pie de Reina 5 Cajones', '', 1.00, 858000.00, 12, 0, 1, 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQZDREqGIa1_y2AAoDTgr0luYvVCj5ioeQ1gg&s', 1, '2026-04-14 22:27:37', '2026-05-01 16:13:20'),
(43, 2, NULL, 'Somier 1 plaza y media (100 x 190)', 'Somier completo con respaldo', 100.00, 840000.00, 8, 4, 1, 'https://encrypted-tbn0.gstatic.com/shopping?q=tbn:ANd9GcTTXf9xv9t98Dl7S1tj46ZM9lfioZdvBHSkMPeMjVC7IIMNop9nbBmYcQxMTkV9tAFv9uZM3dXb5yasCo2MQiisV6vvUUnGDZr3tywj92gsYGzfU0K2h4KZ6g', 1, '2026-04-14 22:31:41', '2026-04-15 14:52:11'),
(44, 2, NULL, 'Somier 1 plaza (80 x 190)', '', 2.00, 800000.00, 8, 3, 1, 'https://decathogar.com.ar/wp-content/uploads/2025/11/1-PLAZA-Y-MEDIA-BARI-20-AZUL.webp', 1, '2026-04-14 22:33:14', '2026-04-18 17:53:24'),
(46, 1, NULL, 'LICUADORA ATMA 500W 1.5L JARRA PLASTICA', '', 1.00, 156000.00, 3, 2, 1, '/assets/uploads/articulos/art_69df9b52939ff5.80367531.jpg', 0, '2026-04-15 10:06:06', '2026-04-15 11:07:54'),
(48, 1, '080-68', 'LICUADORA ATMA 500W 1.5L JARRA PLASTICA', '', 1.00, 156000.00, 3, 2, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/11232/image_1024/%5B080-68659%5D%20LICUADORA%20ATMA%20LI8446AP%20500W%201.5L%20JARRA%20PLASTICA?unique=188a0cd', 1, '2026-04-15 11:08:41', '2026-04-15 14:38:23'),
(49, 5, '117-06064', 'DESMALEZADORA ENERGY BC52/3 52CC', 'Motor: 52 CC. Potencia: 1.45 kW.  Velocidad en vacio: nº 9500/min. Capacidad: 1.25 L. Cuchilla: 3T. Capacidad de corte: 254mm. Peso: 9.5 kg. Medidas: 160 x 26 cm.', 205000.00, 360000.00, 6, 2, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/12532/image_1024/%5B117-06064%5D%20DESMALEZADORA%20ENERGY%20BC52-3%2052CC?unique=612de40', 1, '2026-04-15 11:23:07', '2026-04-23 10:05:22'),
(50, 5, NULL, 'Cocina Peabody', '', 630000.00, 960000.00, 10, 1, 1, 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSqBIcvmYXx5gzcGJ_tS8M8VMQ6X8hv3jTlrg&s', 1, '2026-04-15 11:52:41', '2026-05-01 16:33:47'),
(51, 3, NULL, 'Samsung A16', 'Samsung A16 - 4/128', 1.00, 700000.00, 10, 1, 1, 'https://http2.mlstatic.com/D_NQ_NP_2X_784712-MLA100049642639_122025-F.webp', 1, '2026-04-15 11:55:32', '2026-04-15 14:43:52'),
(52, 2, NULL, 'Combo Somier mas Placard 6', 'Combo somier 1.4 mas ropero 6 puertas + Cesto', 0.97, 1560000.00, 12, 0, 1, 'https://decathogar.com.ar/wp-content/uploads/2025/09/SOMMIER-QUEEN-BARI-CANELONES-NATURAL.webp', 1, '2026-04-15 11:59:36', '2026-05-02 12:42:14'),
(53, 2, NULL, 'Respaldo 100', 'Solo Respaldos', 1.00, 1.00, 1, 5, 1, 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcR0bogruecXLJwkZRzdoBC5pv3vqudJiPMwg1eZOU0kj0QPkuZo', 1, '2026-04-16 12:01:02', '2026-05-14 13:41:36'),
(54, 2, NULL, 'Juego Comedor Premium', '', 1.00, 2256000.00, 12, 1, 1, NULL, 1, '2026-04-22 22:56:49', '2026-04-22 22:56:49'),
(55, 2, NULL, 'Respaldo 140', '', 1.02, 138000.00, 2, 2, 1, 'https://decathogar.com.ar/wp-content/uploads/2024/11/Colores-de-Respaldos.png', 1, '2026-04-23 09:20:13', '2026-05-14 13:41:50'),
(56, 1, '085-00785', 'SmarTv 32', '', 395000.00, 744000.00, 8, 1, 1, 'https://images.fravega.com/f1000/b02f3e46c89fe14a5c4689ba6ac9bc08.jpg', 1, '2026-04-23 09:24:32', '2026-04-23 09:24:32'),
(57, 3, NULL, 'Smartv 50', '', 830000.00, 1300000.00, 10, 0, 1, 'https://encrypted-tbn0.gstatic.com/shopping?q=tbn:ANd9GcT3kA0X4sMpuy3Ia8Bj4PfUH5zrYdp3_YlBaqfZM4Yh0scHeENm5FmKgXAIGQVhU_DWi__0G_o2hRV5dBMp2FfL-1XVVZp6', 1, '2026-04-23 09:33:56', '2026-05-18 09:46:06'),
(58, 5, '024-00752', 'DESMALEZADORA POTENZA 52CC', 'Motor: 52cc. Cilindro: Cromado. Carretel: Doble tanza. Potencia: 1.65kW. Corte: 430mm. Transmisión: Eje recto. Peso: 8.5kg.', 249999.96, 438000.00, 6, 1, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/6381/image_1024/%5B024-00752%5D%20DESMALEZADORA%20POTENZA%20DMS52%2052CC?unique=31c2d30', 1, '2026-04-23 10:06:59', '2026-05-18 12:29:41'),
(59, 9, NULL, 'Somier 140', '', 1.00, 1560000.00, 12, 1, 1, NULL, 1, '2026-05-02 12:38:24', '2026-05-02 12:38:24'),
(60, 9, NULL, 'Colchon 140 Cm', '', 1.00, 720000.00, 6, 0, 1, NULL, 1, '2026-05-02 12:39:18', '2026-05-02 12:43:54'),
(61, 1, NULL, 'HELADERA DREAN 277L RETIRADA', '', 1.00, 1560000.00, 12, 1, 1, NULL, 1, '2026-05-14 13:43:51', '2026-05-14 13:43:51'),
(62, 5, '086-34948', 'BORDEADORA TRAMONTINA  AP1500T 1500W', '', 121000.00, 204000.00, 4, 1, 1, 'https://tucuman.dipromas.com.ar/web/image/product.product/7531/image_1024/%5B086-34948%5D%20BORDEADORA%20TRAMONTINA%2079634-948%20AP1500T%201500W?unique=3af7709', 1, '2026-05-18 11:13:38', '2026-05-18 11:13:38'),
(63, 2, NULL, 'Espejo Vertical', '', 420000.00, 420000.00, 6, 1, 1, NULL, 1, '2026-05-18 11:24:38', '2026-05-18 11:24:38'),
(64, 5, NULL, 'Bicicleta R29', '', 1.00, 936000.00, 8, 1, 1, 'https://http2.mlstatic.com/D_NQ_NP_2X_947564-MLA109507862222_042026-F.webp', 1, '2026-05-18 11:27:32', '2026-05-18 11:28:20');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `categorias`
--

CREATE TABLE `categorias` (
  `id` smallint UNSIGNED NOT NULL,
  `nombre` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `icono` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bi-box',
  `activo` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `categorias`
--

INSERT INTO `categorias` (`id`, `nombre`, `icono`, `activo`) VALUES
(1, 'Electrodomésticos', 'bi-tv', 1),
(2, 'Muebles', 'bi-house', 1),
(3, 'Tecnología', 'bi-phone', 1),
(4, 'Ropa y Calzado', 'bi-bag', 0),
(5, 'Otros', 'bi-grid', 1),
(6, 'Cuidado Personal', 'bi-heart', 1),
(7, 'Climatizacion', 'bi-grid', 1),
(8, 'Sillones', 'bi-house', 1),
(9, 'Somier', 'bi-heart', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `clientes`
--

CREATE TABLE `clientes` (
  `id` int UNSIGNED NOT NULL,
  `nombre` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellido` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `dni` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `celular` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `direccion` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `localidad` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `provincia_id` tinyint UNSIGNED NOT NULL,
  `observaciones` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `clientes`
--

INSERT INTO `clientes` (`id`, `nombre`, `apellido`, `dni`, `celular`, `direccion`, `localidad`, `provincia_id`, `observaciones`, `created_at`, `updated_at`) VALUES
(1, 'Juan', 'Perez', NULL, '3815096109', 'aoosaosoasdadasd', 'sda', 3, '', '2026-03-27 19:41:47', '2026-03-27 19:41:47'),
(2, 'Daniel Alejandro', 'Quevedo', NULL, '123123123123', 'Octaviano Vera 845', 'San Miguel de Tucumán', 3, '', '2026-03-27 19:47:01', '2026-03-27 19:47:01'),
(3, 'Daniel Alejandro', 'Quevedo', NULL, '+543815096109', 'Octaviano Vera 845', 'San Miguel de Tucumán', 3, '', '2026-03-27 19:58:02', '2026-03-27 19:58:02'),
(4, 'Prueba', 'Pruej', NULL, '3815096409', 'Ksjsjsjs', 'Tuc', 3, '', '2026-03-28 16:52:41', '2026-03-28 16:52:41'),
(5, 'das', 'Que', '11111111', '3815096109', '1s1s1s1s', 'ssss', 3, '', '2026-04-01 14:40:24', '2026-04-01 14:40:24'),
(6, 'fabiana', 'sayago', '30178559', '3854094070', 'mza 10 lote 13', 'santiago capital', 2, '', '2026-04-06 23:07:28', '2026-04-06 23:07:28'),
(7, 'norma Vanesa', 'Coronel', '30918262', '3855968813', 'calle 15 y 6ª pje', 'bª Almirante Browm', 2, '', '2026-04-06 23:15:46', '2026-04-06 23:15:46'),
(8, 'Jesica Viviana', 'Luna', '33886181', '3853127665', 'lote 61 s/N', 'esperanza (puestito)', 2, '', '2026-04-06 23:19:11', '2026-04-06 23:19:11'),
(9, 'Carla Varela', 'Veliz', '45161400', '3856288322', 'Ruta 9km 1122', 'Zanjon', 2, '', '2026-04-06 23:22:58', '2026-04-06 23:22:58'),
(10, 'daniela', 'campos', '42348161', '3854040778', 'mza 27 lote 16', 'favaloro', 2, '', '2026-04-13 20:44:24', '2026-04-13 20:44:24'),
(11, 'carolina del valle', 'figueroa', '25596082', '3854888793', 'independencia y prolongacion', 'maco', 2, '', '2026-04-13 20:48:40', '2026-04-13 20:48:40'),
(12, 'gisela de los angeles', 'alvarez', '34195725', '3855883962', 'independencia prolongacion', 'maco', 2, '', '2026-04-13 20:52:35', '2026-04-13 20:52:35'),
(13, 'Aurelia Francisca', 'pereyra', '16404708', '3854357785', 'calle 13', 'simbolar', 2, '', '2026-04-13 20:56:10', '2026-04-13 20:56:10'),
(14, 'Alberto Alejandro', 'Ibañez', '38718831', '3854982711', 'camino ala costa ( calle publica )', 'los flores', 2, '', '2026-04-13 21:02:01', '2026-04-13 21:02:01'),
(15, 'carlota Azucena', 'Banegas', '29894073', '3855790336', 'independencia prolongacion', 'maco', 2, '', '2026-04-13 21:04:30', '2026-04-13 21:04:30'),
(16, 'paola', 'zanni', '27063826', '3854090987', 'granadero saavedra 1684', 'sarmiento', 2, '', '2026-04-13 21:30:40', '2026-04-13 21:30:40'),
(17, 'zulma Beatriz', 'Ibañez', '28594754', '3856137132', 'juan diego 5781 (camino ala costa)', 'los flores', 2, '', '2026-04-13 21:40:36', '2026-04-13 21:40:36'),
(18, 'gonzalo', 'veliz', '38559585', '3854875803', 'mza 75 lote 14', 'vinalar', 2, '', '2026-04-18 17:48:59', '2026-04-18 17:48:59'),
(19, 'raul eduardo', 'juarez', '32505979', '3856772534', 'av.lugones y moyano', 'puestito san antonio', 2, '', '2026-04-18 17:53:24', '2026-04-18 17:53:24'),
(20, 'silvia noemi', 'ladriel', '22425899', '3854048292', 'ruta 9 km 1126', 'Zanjon', 2, '', '2026-04-18 17:56:25', '2026-04-18 17:56:25'),
(21, 'adriana', 'sayago', '37139378', '3854844327', 'ruta 9 camino buen aire', 'Zanjon', 2, '', '2026-04-18 17:59:52', '2026-04-18 17:59:52'),
(22, 'irene del valle', 'torres', '24013853', '3854856542', 'viejo palomar', 'Zanjon', 2, '', '2026-04-18 18:03:19', '2026-04-18 18:03:19'),
(23, 'lilia ester', 'caceres', '06244787', '3855032115', 'ruta 9', 'yanda0', 2, '', '2026-04-18 18:07:40', '2026-04-18 18:07:40'),
(24, 'marcela noemi', 'ledesma', '35344589', '3855913673', 'ejercito de los andes 840', 'san martin', 2, '', '2026-04-18 18:12:49', '2026-04-18 18:12:49'),
(25, 'luisa soledad', 'leiva', '28900819', '3854728599', 'caceros 1284', 'san martin', 2, '', '2026-04-18 18:15:38', '2026-04-18 18:15:38'),
(26, 'jose emiliano', 'mancilla', '36620891', '3854963871', 'gob.jose lami viera 394', 'bª Almirante Browm', 2, '', '2026-04-18 18:21:55', '2026-04-18 18:21:55'),
(27, 'emiliano sebastian', 'perez', '43622222', '3854989893', 'camino la costa s/n c. publica', 'los flores', 2, '', '2026-04-18 18:25:20', '2026-04-18 18:25:20'),
(28, 'susana del valle', 'coronel', '21631262', '3854178654', 'calle publica ( camino ala costa )', 'los flores', 2, '', '2026-04-18 18:29:32', '2026-04-18 18:29:32'),
(29, 'victoria', 'beatriz', '35344659', '3855761139', 'camino ala costa', 'los cardosos', 2, '', '2026-04-18 18:35:49', '2026-04-18 18:35:49'),
(30, 'brenda nataly', 'arce', '48212334', '3855360349', 'sanjon', 'sanjon', 2, 'hija de clienta', '2026-05-01 16:11:12', '2026-05-01 16:11:12'),
(31, 'vanny', 'dorado', '43060378', '3855771427', 'santa fe 1605', 'sarmiento', 2, '', '2026-05-01 16:13:20', '2026-05-01 16:13:20'),
(32, 'jimena anahi', 'vasquez', '48212348', '3854844327', 'camino el buen abre', 'sanjon', 2, '', '2026-05-01 16:16:11', '2026-05-01 16:16:11'),
(33, 'walter edgardo', 'villarreal', '29788011', '3854761090', 'ruta 9 klm 1123', 'sanjon', 2, '', '2026-05-01 16:18:13', '2026-05-01 16:18:13'),
(34, 'francisco fernando', 'guzman', '27615663', '3854095626', 'mza 73 lote 7', 'siglo 21', 2, '', '2026-05-01 16:20:26', '2026-05-01 16:20:26'),
(35, 'antonela roxana', 'astorga', '45269933', '3856121966', 'zona rural', 'makito', 2, 'ruta 9 camino de tuti (independencia cancha de velez )', '2026-05-01 16:25:32', '2026-05-01 16:25:32'),
(36, 'norma alicia', 'tula', '5956084', '3855968813', 'calle 15 y 6to pje', 'almirante brom', 2, '', '2026-05-01 16:29:36', '2026-05-01 16:29:36'),
(37, 'ana belen', 'tula', '42521716', '1137763256', 'camino ala carlota', 'makito', 2, '', '2026-05-01 16:33:47', '2026-05-01 16:33:47'),
(38, 'Rosa Noemi', 'Nuñez', '29894104', '3854339128', 'Santa rosa y posada 1775', 'San Lucia', 2, '', '2026-05-02 12:42:14', '2026-05-02 12:42:14'),
(39, 'Matias Alejandro', 'Ferreyra', '29894469', '3856786670', 'Zona Rural S/n', 'Maquito', 2, '', '2026-05-02 12:43:54', '2026-05-02 12:43:54'),
(40, 'Claudia', 'Tamacho', '26161513', '3856167161', '1re pj Macarro de Torres', 'Centenario', 2, '', '2026-05-18 09:46:06', '2026-05-18 09:46:06');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `provincias`
--

CREATE TABLE `provincias` (
  `id` tinyint UNSIGNED NOT NULL,
  `nombre` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `provincias`
--

INSERT INTO `provincias` (`id`, `nombre`) VALUES
(1, 'Catamarca'),
(2, 'Santiago del Estero'),
(3, 'Tucumán');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `stock_movimientos`
--

CREATE TABLE `stock_movimientos` (
  `id` int UNSIGNED NOT NULL,
  `articulo_id` mediumint UNSIGNED NOT NULL,
  `usuario_id` int UNSIGNED NOT NULL,
  `tipo` enum('entrada','salida','ajuste') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cantidad` smallint NOT NULL,
  `stock_antes` smallint NOT NULL,
  `stock_despues` smallint NOT NULL,
  `referencia` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `stock_movimientos`
--

INSERT INTO `stock_movimientos` (`id`, `articulo_id`, `usuario_id`, `tipo`, `cantidad`, `stock_antes`, `stock_despues`, `referencia`, `created_at`) VALUES
(1, 1, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-03-27 18:34:58'),
(2, 2, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-03-27 19:07:48'),
(3, 2, 3, 'salida', 1, 1, 0, 'Venta #1', '2026-03-27 19:41:47'),
(4, 1, 3, 'salida', 1, 1, 0, 'Venta #2', '2026-03-27 19:47:01'),
(5, 1, 2, 'salida', 1, 1, 0, 'Venta #3', '2026-03-27 19:58:02'),
(6, 3, 3, 'entrada', 3, 0, 3, 'Alta de artículo', '2026-03-28 15:26:22'),
(7, 4, 3, 'entrada', 2, 0, 2, 'Alta de artículo', '2026-03-28 15:28:49'),
(8, 5, 3, 'entrada', 3, 0, 3, 'Alta de artículo', '2026-03-28 15:30:20'),
(9, 6, 3, 'entrada', 3, 0, 3, 'Alta de artículo', '2026-03-28 15:32:23'),
(10, 7, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-03-28 15:34:56'),
(11, 8, 3, 'entrada', 2, 0, 2, 'Alta de artículo', '2026-03-28 15:36:05'),
(12, 9, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-03-28 15:59:56'),
(13, 10, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-03-28 16:03:02'),
(14, 11, 3, 'entrada', 2, 0, 2, 'Alta de artículo', '2026-03-28 16:21:35'),
(15, 12, 3, 'entrada', 2, 0, 2, 'Alta de artículo', '2026-03-28 16:41:24'),
(16, 13, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-03-28 16:49:47'),
(17, 14, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-03-28 16:51:09'),
(18, 13, 7, 'salida', 1, 1, 0, 'Venta #4', '2026-03-28 16:52:41'),
(19, 15, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-03-31 14:47:03'),
(20, 16, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-03-31 14:48:45'),
(21, 17, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-03-31 14:53:16'),
(22, 18, 3, 'entrada', 2, 0, 2, 'Alta de artículo', '2026-03-31 15:02:22'),
(23, 19, 3, 'entrada', 2, 0, 2, 'Alta de artículo', '2026-04-01 13:55:07'),
(24, 16, 3, 'salida', 1, 1, 0, 'Venta #5', '2026-04-01 14:40:24'),
(25, 20, 3, 'entrada', 2, 0, 2, 'Alta de artículo', '2026-04-01 14:57:37'),
(26, 21, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-04-01 15:21:50'),
(27, 22, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-04-01 15:25:32'),
(28, 24, 3, 'entrada', 2, 0, 2, 'Alta de artículo', '2026-04-01 15:31:11'),
(29, 25, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-04-01 16:17:58'),
(30, 26, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-04-01 16:20:08'),
(31, 27, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-04-01 16:23:01'),
(32, 28, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-04-01 16:24:39'),
(33, 29, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-04-01 16:28:24'),
(34, 30, 3, 'entrada', 3, 0, 3, 'Alta de artículo', '2026-04-01 17:00:26'),
(35, 31, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-04-01 17:02:43'),
(36, 32, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-04-01 17:03:55'),
(37, 33, 3, 'entrada', 2, 0, 2, 'Alta de artículo', '2026-04-01 17:07:08'),
(38, 34, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-04-02 17:33:33'),
(39, 10, 7, 'salida', 1, 1, 0, 'Venta #6', '2026-04-06 23:07:28'),
(40, 34, 7, 'salida', 1, 1, 0, 'Venta #7', '2026-04-06 23:15:46'),
(41, 4, 7, 'salida', 1, 2, 1, 'Venta #8', '2026-04-06 23:19:11'),
(42, 12, 7, 'salida', 1, 2, 1, 'Venta #9', '2026-04-06 23:22:58'),
(43, 35, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-04-07 17:44:09'),
(44, 36, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-04-07 17:47:23'),
(45, 37, 3, 'entrada', 3, 0, 3, 'Alta de artículo', '2026-04-10 17:05:56'),
(46, 10, 3, 'entrada', 3, 0, 3, 'Ajuste manual', '2026-04-10 17:07:12'),
(47, 20, 3, 'entrada', 1, 2, 3, 'Ajuste manual', '2026-04-10 17:10:23'),
(48, 35, 7, 'salida', 1, 1, 0, 'Venta #10', '2026-04-13 20:44:24'),
(49, 9, 7, 'salida', 1, 1, 0, 'Venta #11', '2026-04-13 20:48:40'),
(50, 6, 7, 'salida', 1, 3, 2, 'Venta #12', '2026-04-13 20:52:35'),
(51, 10, 7, 'salida', 1, 3, 2, 'Venta #13', '2026-04-13 20:56:10'),
(52, 3, 7, 'salida', 1, 3, 2, 'Venta #14', '2026-04-13 21:02:01'),
(53, 3, 7, 'salida', 1, 2, 1, 'Venta #15', '2026-04-13 21:04:30'),
(54, 37, 7, 'salida', 1, 3, 2, 'Venta #16', '2026-04-13 21:30:40'),
(55, 5, 7, 'salida', 1, 3, 2, 'Venta #17', '2026-04-13 21:40:36'),
(56, 35, 5, 'entrada', 1, 0, 1, 'Ajuste manual', '2026-04-14 11:51:35'),
(57, 10, 3, 'salida', 2, 2, 0, 'Ajuste manual', '2026-04-14 16:43:51'),
(58, 9, 3, 'entrada', 1, 0, 1, 'Ajuste manual', '2026-04-14 21:41:59'),
(59, 38, 3, 'entrada', 2, 0, 2, 'Alta de artículo', '2026-04-14 22:02:15'),
(60, 39, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-04-14 22:05:44'),
(61, 35, 3, 'salida', 1, 1, 0, 'Ajuste manual', '2026-04-14 22:06:29'),
(62, 39, 3, 'entrada', 1, 1, 2, 'Ajuste manual', '2026-04-14 22:11:06'),
(63, 40, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-04-14 22:12:40'),
(64, 41, 3, 'entrada', 2, 0, 2, 'Alta de artículo', '2026-04-14 22:20:38'),
(65, 9, 3, 'entrada', 2, 1, 3, 'Ajuste manual', '2026-04-14 22:24:52'),
(66, 9, 3, 'entrada', 1, 3, 4, 'Ajuste manual', '2026-04-14 22:25:10'),
(67, 42, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-04-14 22:27:37'),
(68, 43, 3, 'entrada', 4, 0, 4, 'Alta de artículo', '2026-04-14 22:31:41'),
(69, 44, 3, 'entrada', 2, 0, 2, 'Alta de artículo', '2026-04-14 22:33:14'),
(70, 46, 3, 'entrada', 2, 0, 2, 'Alta de artículo', '2026-04-15 10:06:06'),
(71, 48, 3, 'entrada', 2, 0, 2, 'Alta de artículo', '2026-04-15 11:08:41'),
(72, 49, 3, 'entrada', 2, 0, 2, 'Alta de artículo', '2026-04-15 11:23:07'),
(73, 50, 3, 'entrada', 2, 0, 2, 'Alta de artículo', '2026-04-15 11:52:41'),
(74, 50, 3, 'entrada', 1, 2, 3, 'Ajuste manual', '2026-04-15 11:53:29'),
(75, 51, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-04-15 11:55:32'),
(76, 21, 3, 'salida', 1, 1, 0, 'Ajuste manual', '2026-04-15 11:58:20'),
(77, 52, 3, 'entrada', 4, 0, 4, 'Alta de artículo', '2026-04-15 11:59:36'),
(78, 52, 3, 'entrada', 1, 4, 5, 'Ajuste manual', '2026-04-15 12:04:12'),
(79, 29, 3, 'entrada', 1, 1, 2, 'Ajuste manual', '2026-04-16 11:54:51'),
(80, 38, 3, 'salida', 2, 2, 0, 'Ajuste manual', '2026-04-16 11:57:19'),
(81, 31, 3, 'salida', 1, 1, 0, 'Ajuste manual', '2026-04-16 11:57:26'),
(82, 38, 3, 'entrada', 1, 0, 1, 'Ajuste manual', '2026-04-16 11:57:39'),
(83, 39, 3, 'salida', 1, 2, 1, 'Ajuste manual', '2026-04-16 11:58:16'),
(84, 31, 3, 'entrada', 1, 0, 1, 'Ajuste manual', '2026-04-16 11:58:30'),
(85, 44, 3, 'entrada', 2, 2, 4, 'Ajuste manual', '2026-04-16 11:58:51'),
(86, 53, 3, 'entrada', 4, 0, 4, 'Alta de artículo', '2026-04-16 12:01:02'),
(87, 30, 7, 'salida', 1, 3, 2, 'Venta #18', '2026-04-18 17:48:59'),
(88, 44, 7, 'salida', 1, 4, 3, 'Venta #19', '2026-04-18 17:53:24'),
(89, 52, 7, 'salida', 1, 5, 4, 'Venta #20', '2026-04-18 17:56:25'),
(90, 7, 7, 'salida', 1, 1, 0, 'Venta #21', '2026-04-18 17:59:52'),
(91, 6, 7, 'salida', 1, 2, 1, 'Venta #22', '2026-04-18 18:03:19'),
(92, 50, 7, 'salida', 1, 3, 2, 'Venta #23', '2026-04-18 18:07:40'),
(93, 36, 7, 'salida', 1, 1, 0, 'Venta #24', '2026-04-18 18:12:49'),
(94, 52, 7, 'salida', 1, 4, 3, 'Venta #25', '2026-04-18 18:15:38'),
(95, 50, 7, 'salida', 1, 2, 1, 'Venta #26', '2026-04-18 18:21:55'),
(96, 52, 7, 'salida', 1, 3, 2, 'Venta #27', '2026-04-18 18:25:20'),
(97, 52, 7, 'salida', 1, 2, 1, 'Venta #28', '2026-04-18 18:29:32'),
(98, 37, 7, 'salida', 1, 2, 1, 'Venta #29', '2026-04-18 18:35:49'),
(99, 54, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-04-22 22:56:49'),
(100, 50, 3, 'entrada', 1, 1, 2, 'Ajuste manual', '2026-04-23 09:09:43'),
(101, 10, 3, 'entrada', 1, 0, 1, 'Ajuste manual', '2026-04-23 09:10:01'),
(102, 7, 3, 'entrada', 1, 0, 1, 'Ajuste manual', '2026-04-23 09:10:23'),
(103, 49, 3, 'entrada', 2, 2, 4, 'Ajuste manual', '2026-04-23 09:10:40'),
(104, 3, 3, 'entrada', 1, 1, 2, 'Ajuste manual', '2026-04-23 09:10:52'),
(105, 55, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-04-23 09:20:13'),
(106, 34, 3, 'entrada', 1, 0, 1, 'Ajuste manual', '2026-04-23 09:21:35'),
(107, 56, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-04-23 09:24:32'),
(108, 57, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-04-23 09:33:56'),
(109, 49, 3, 'salida', 2, 4, 2, 'Ajuste manual', '2026-04-23 10:05:22'),
(110, 58, 3, 'entrada', 2, 0, 2, 'Alta de artículo', '2026-04-23 10:06:59'),
(111, 26, 7, 'salida', 1, 1, 0, 'Venta #30', '2026-05-01 16:11:12'),
(112, 42, 7, 'salida', 1, 1, 0, 'Venta #31', '2026-05-01 16:13:20'),
(113, 52, 7, 'salida', 1, 1, 0, 'Venta #32', '2026-05-01 16:16:11'),
(114, 58, 7, 'salida', 1, 2, 1, 'Venta #33', '2026-05-01 16:18:13'),
(115, 58, 7, 'salida', 1, 1, 0, 'Venta #34', '2026-05-01 16:20:26'),
(116, 3, 7, 'salida', 1, 2, 1, 'Venta #35', '2026-05-01 16:25:32'),
(117, 6, 7, 'salida', 1, 1, 0, 'Venta #36', '2026-05-01 16:29:36'),
(118, 50, 7, 'salida', 1, 2, 1, 'Venta #37', '2026-05-01 16:33:47'),
(119, 52, 3, 'entrada', 1, 0, 1, 'Ajuste manual', '2026-05-02 12:24:42'),
(120, 59, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-05-02 12:38:24'),
(121, 60, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-05-02 12:39:18'),
(122, 52, 7, 'salida', 1, 1, 0, 'Venta #38', '2026-05-02 12:42:14'),
(123, 60, 7, 'salida', 1, 1, 0, 'Venta #39', '2026-05-02 12:43:54'),
(124, 53, 3, 'entrada', 1, 4, 5, 'Ajuste manual', '2026-05-14 13:41:36'),
(125, 55, 3, 'entrada', 1, 1, 2, 'Ajuste manual', '2026-05-14 13:41:50'),
(126, 21, 3, 'entrada', 1, 0, 1, 'Ajuste manual', '2026-05-14 13:42:06'),
(127, 61, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-05-14 13:43:51'),
(128, 57, 7, 'salida', 1, 1, 0, 'Venta #40', '2026-05-18 09:46:06'),
(129, 62, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-05-18 11:13:38'),
(130, 63, 3, 'entrada', 1, 0, 1, 'Alta de artículo', '2026-05-18 11:24:38'),
(131, 64, 3, 'entrada', 2, 0, 2, 'Alta de artículo', '2026-05-18 11:27:32'),
(132, 64, 3, 'salida', 1, 2, 1, 'Ajuste manual', '2026-05-18 11:28:20'),
(133, 58, 6, 'entrada', 1, 0, 1, 'Ajuste manual', '2026-05-18 12:29:41');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int UNSIGNED NOT NULL,
  `usuario` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellido` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `clave` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rol` enum('admin','vendedor') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'vendedor',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `ultimo_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id`, `usuario`, `nombre`, `apellido`, `clave`, `rol`, `activo`, `ultimo_login`, `created_at`) VALUES
(1, 'admin', 'Admin', 'Imperio', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 0, NULL, '2026-03-27 16:50:56'),
(2, 'vendedor', 'Vendedor', 'Demo', '$2y$12$z7gsppPNfyTgvWQQpvYNduVpeJOrwDUungiVm9Fji3Z/zHOdYL0oG', 'vendedor', 0, '2026-03-27 19:57:00', '2026-03-27 16:50:56'),
(3, 'danqueve', 'Admin', 'Imperio', '$2y$10$12OCSwSW3HwKdFl55RjlR.Jy5YHU3jSProuqpSA8i7cajcH0JJsMy', 'admin', 1, '2026-05-18 11:12:23', '2026-03-27 16:56:52'),
(4, '41299548', 'Agustina', 'Leguizamon', '$2y$12$gvzKPzSkwWRsXm6fT/n3G.Ra97puwpidyzVGqmoQjiQVBwFs.p/jm', 'admin', 1, '2026-04-14 15:23:16', '2026-03-28 15:05:58'),
(5, '48007017', 'Emilia', 'Medina', '$2y$12$IO1a.OL.3X8zG1/DdAl6rO0kPOxAYwj5BUs/v5bgoA9W4g3WnqQwi', 'admin', 1, '2026-05-07 20:30:15', '2026-03-28 15:06:22'),
(6, '34603619', 'Mohamed', 'Mustafa', '$2y$12$QW3hOOe6gWsyKP4uXSFXqeGdYgdgmL0TaWAP21y0.4pXkJgxz5jXG', 'admin', 1, '2026-05-16 18:07:41', '2026-03-28 15:06:44'),
(7, '39360197', 'Gonzalo', 'Carrazan', '$2y$12$T2TMyc.m0BPaQdzKAx4Pk.lXEriqo5I9hZJ1TlP3c36VLm.3kM2AC', 'vendedor', 1, '2026-05-18 09:40:54', '2026-03-28 15:07:11');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ventas`
--

CREATE TABLE `ventas` (
  `id` int UNSIGNED NOT NULL,
  `cliente_id` int UNSIGNED NOT NULL,
  `vendedor_id` int UNSIGNED NOT NULL,
  `tipo_pago` enum('contado','financiado') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cuotas` tinyint UNSIGNED NOT NULL DEFAULT '1',
  `es_mensual` tinyint(1) NOT NULL DEFAULT '0',
  `primer_vencimiento` date DEFAULT NULL,
  `total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `estado` enum('pendiente','confirmada','anulada') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'confirmada',
  `observaciones` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `ventas`
--

INSERT INTO `ventas` (`id`, `cliente_id`, `vendedor_id`, `tipo_pago`, `cuotas`, `es_mensual`, `primer_vencimiento`, `total`, `estado`, `observaciones`, `created_at`) VALUES
(1, 1, 3, 'financiado', 12, 0, NULL, 1320000.00, 'anulada', '', '2026-03-27 19:41:47'),
(2, 2, 3, 'financiado', 12, 0, NULL, 1752000.00, 'anulada', '', '2026-03-27 19:47:01'),
(3, 3, 2, 'financiado', 12, 0, NULL, 1752000.00, 'anulada', '', '2026-03-27 19:58:02'),
(4, 4, 7, 'financiado', 8, 0, NULL, 324000.00, 'anulada', '', '2026-03-28 16:52:41'),
(5, 5, 3, 'financiado', 8, 1, '2026-04-04', 369000.00, 'anulada', '', '2026-04-01 14:40:24'),
(6, 6, 7, 'financiado', 10, 1, '2026-05-10', 498000.00, 'confirmada', '', '2026-04-06 23:07:28'),
(7, 7, 7, 'financiado', 12, 1, '2026-05-30', 1968000.00, 'confirmada', '', '2026-04-06 23:15:46'),
(8, 8, 7, 'financiado', 10, 1, '2026-05-16', 753000.00, 'confirmada', '', '2026-04-06 23:19:11'),
(9, 9, 7, 'financiado', 6, 1, '2026-05-15', 264000.00, 'confirmada', '', '2026-04-06 23:22:58'),
(10, 10, 7, 'financiado', 12, 1, '2026-05-10', 1800000.00, 'confirmada', '', '2026-04-13 20:44:24'),
(11, 11, 7, 'financiado', 3, 1, '2026-05-15', 93000.00, 'confirmada', '', '2026-04-13 20:48:40'),
(12, 12, 7, 'financiado', 8, 1, '2026-05-30', 465000.00, 'confirmada', '', '2026-04-13 20:52:35'),
(13, 13, 7, 'financiado', 10, 1, '2026-05-18', 498000.00, 'confirmada', '', '2026-04-13 20:56:10'),
(14, 14, 7, 'financiado', 12, 1, '2026-05-07', 1626000.00, 'confirmada', '', '2026-04-13 21:02:01'),
(15, 15, 7, 'financiado', 12, 1, '2026-05-15', 1626000.00, 'confirmada', '', '2026-04-13 21:04:30'),
(16, 16, 7, 'financiado', 10, 1, '2026-05-10', 750000.00, 'confirmada', '', '2026-04-13 21:30:40'),
(17, 17, 7, 'financiado', 10, 1, '2026-05-06', 585000.00, 'confirmada', '', '2026-04-13 21:40:36'),
(18, 18, 7, 'financiado', 10, 1, '2026-05-24', 700000.00, 'confirmada', '', '2026-04-18 17:48:59'),
(19, 19, 7, 'financiado', 8, 1, '2026-05-16', 800000.00, 'confirmada', '', '2026-04-18 17:53:24'),
(20, 20, 7, 'financiado', 12, 1, '2026-05-30', 1560000.00, 'confirmada', '', '2026-04-18 17:56:25'),
(21, 21, 7, 'financiado', 12, 1, '2026-05-25', 1611000.00, 'confirmada', '', '2026-04-18 17:59:52'),
(22, 22, 7, 'financiado', 8, 1, '2026-05-10', 465000.00, 'confirmada', '', '2026-04-18 18:03:19'),
(23, 23, 7, 'financiado', 10, 1, '2026-05-29', 900000.00, 'confirmada', '', '2026-04-18 18:07:40'),
(24, 24, 7, 'financiado', 12, 1, '2026-05-25', 1320000.00, 'confirmada', '', '2026-04-18 18:12:49'),
(25, 25, 7, 'financiado', 12, 1, '2026-05-30', 1560000.00, 'confirmada', '', '2026-04-18 18:15:38'),
(26, 26, 7, 'financiado', 10, 1, '2026-05-10', 900000.00, 'confirmada', '', '2026-04-18 18:21:55'),
(27, 27, 7, 'financiado', 12, 1, '2026-05-20', 1560000.00, 'confirmada', '', '2026-04-18 18:25:20'),
(28, 28, 7, 'financiado', 12, 1, '2026-05-30', 1560000.00, 'confirmada', '', '2026-04-18 18:29:32'),
(29, 29, 7, 'financiado', 10, 1, '2026-04-26', 750000.00, 'confirmada', '', '2026-04-18 18:35:49'),
(30, 30, 7, 'financiado', 5, 1, '2026-05-30', 550000.00, 'confirmada', 'hija de clienta', '2026-05-01 16:11:12'),
(31, 31, 7, 'financiado', 12, 1, '2026-05-24', 858000.00, 'confirmada', '', '2026-05-01 16:13:20'),
(32, 32, 7, 'financiado', 12, 1, '2026-05-25', 1560000.00, 'confirmada', '', '2026-05-01 16:16:11'),
(33, 33, 7, 'financiado', 6, 1, '2026-05-15', 438000.00, 'confirmada', '', '2026-05-01 16:18:13'),
(34, 34, 7, 'financiado', 6, 1, '2026-05-20', 438000.00, 'confirmada', '', '2026-05-01 16:20:26'),
(35, 35, 7, 'financiado', 12, 1, '2026-05-28', 1695000.00, 'confirmada', 'ruta 9 camino de tuti (independencia cancha de velez )', '2026-05-01 16:25:32'),
(36, 36, 7, 'financiado', 8, 1, '2026-05-28', 465000.00, 'confirmada', '', '2026-05-01 16:29:36'),
(37, 37, 7, 'financiado', 10, 1, '2026-06-10', 960000.00, 'confirmada', '', '2026-05-01 16:33:47'),
(38, 38, 7, 'financiado', 12, 1, '2026-06-20', 1560000.00, 'confirmada', '', '2026-05-02 12:42:14'),
(39, 39, 7, 'financiado', 6, 1, '2026-05-30', 720000.00, 'confirmada', '', '2026-05-02 12:43:54'),
(40, 40, 7, 'financiado', 10, 1, '2026-06-15', 1300000.00, 'confirmada', '', '2026-05-18 09:46:06');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `venta_detalles`
--

CREATE TABLE `venta_detalles` (
  `id` int UNSIGNED NOT NULL,
  `venta_id` int UNSIGNED NOT NULL,
  `articulo_id` mediumint UNSIGNED NOT NULL,
  `cantidad` smallint NOT NULL DEFAULT '1',
  `precio_unitario` decimal(12,2) NOT NULL,
  `subtotal` decimal(14,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `venta_detalles`
--

INSERT INTO `venta_detalles` (`id`, `venta_id`, `articulo_id`, `cantidad`, `precio_unitario`, `subtotal`) VALUES
(1, 1, 2, 1, 1320000.00, 1320000.00),
(2, 2, 1, 1, 1752000.00, 1752000.00),
(3, 3, 1, 1, 1752000.00, 1752000.00),
(4, 4, 13, 1, 324000.00, 324000.00),
(5, 5, 16, 1, 369000.00, 369000.00),
(6, 6, 10, 1, 498000.00, 498000.00),
(7, 7, 34, 1, 1968000.00, 1968000.00),
(8, 8, 4, 1, 753000.00, 753000.00),
(9, 9, 12, 1, 264000.00, 264000.00),
(10, 10, 35, 1, 1800000.00, 1800000.00),
(11, 11, 9, 1, 93000.00, 93000.00),
(12, 12, 6, 1, 465000.00, 465000.00),
(13, 13, 10, 1, 498000.00, 498000.00),
(14, 14, 3, 1, 1626000.00, 1626000.00),
(15, 15, 3, 1, 1626000.00, 1626000.00),
(16, 16, 37, 1, 750000.00, 750000.00),
(17, 17, 5, 1, 585000.00, 585000.00),
(18, 18, 30, 1, 700000.00, 700000.00),
(19, 19, 44, 1, 800000.00, 800000.00),
(20, 20, 52, 1, 1560000.00, 1560000.00),
(21, 21, 7, 1, 1611000.00, 1611000.00),
(22, 22, 6, 1, 465000.00, 465000.00),
(23, 23, 50, 1, 900000.00, 900000.00),
(24, 24, 36, 1, 1320000.00, 1320000.00),
(25, 25, 52, 1, 1560000.00, 1560000.00),
(26, 26, 50, 1, 900000.00, 900000.00),
(27, 27, 52, 1, 1560000.00, 1560000.00),
(28, 28, 52, 1, 1560000.00, 1560000.00),
(29, 29, 37, 1, 750000.00, 750000.00),
(30, 30, 26, 1, 550000.00, 550000.00),
(31, 31, 42, 1, 858000.00, 858000.00),
(32, 32, 52, 1, 1560000.00, 1560000.00),
(33, 33, 58, 1, 438000.00, 438000.00),
(34, 34, 58, 1, 438000.00, 438000.00),
(35, 35, 3, 1, 1695000.00, 1695000.00),
(36, 36, 6, 1, 465000.00, 465000.00),
(37, 37, 50, 1, 960000.00, 960000.00),
(38, 38, 52, 1, 1560000.00, 1560000.00),
(39, 39, 60, 1, 720000.00, 720000.00),
(40, 40, 57, 1, 1300000.00, 1300000.00);

--
-- Disparadores `venta_detalles`
--
DELIMITER $$
CREATE TRIGGER `trg_descuento_stock` AFTER INSERT ON `venta_detalles` FOR EACH ROW BEGIN
    DECLARE v_stock_antes SMALLINT;
    DECLARE v_venta_estado VARCHAR(20);

    SELECT estado INTO v_venta_estado FROM ventas WHERE id = NEW.venta_id;

    IF v_venta_estado = 'confirmada' THEN
        SELECT stock_actual INTO v_stock_antes
        FROM articulos WHERE id = NEW.articulo_id;

        UPDATE articulos
           SET stock_actual = stock_actual - NEW.cantidad
         WHERE id = NEW.articulo_id;

        INSERT INTO stock_movimientos
            (articulo_id, usuario_id, tipo, cantidad, stock_antes, stock_despues, referencia)
        SELECT NEW.articulo_id,
               v.vendedor_id,
               'salida',
               NEW.cantidad,
               v_stock_antes,
               v_stock_antes - NEW.cantidad,
               CONCAT('Venta #', NEW.venta_id)
        FROM ventas v WHERE v.id = NEW.venta_id;
    END IF;
END
$$
DELIMITER ;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `articulos`
--
ALTER TABLE `articulos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `codigo` (`codigo`),
  ADD KEY `idx_categoria` (`categoria_id`),
  ADD KEY `idx_stock` (`stock_actual`),
  ADD KEY `idx_activo` (`activo`);
ALTER TABLE `articulos` ADD FULLTEXT KEY `idx_busqueda` (`nombre`,`descripcion`);

--
-- Indices de la tabla `categorias`
--
ALTER TABLE `categorias`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `clientes`
--
ALTER TABLE `clientes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_apellido` (`apellido`),
  ADD KEY `idx_celular` (`celular`),
  ADD KEY `idx_provincia` (`provincia_id`);

--
-- Indices de la tabla `provincias`
--
ALTER TABLE `provincias`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `stock_movimientos`
--
ALTER TABLE `stock_movimientos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_articulo` (`articulo_id`),
  ADD KEY `idx_fecha` (`created_at`),
  ADD KEY `fk_mov_usr` (`usuario_id`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `usuario` (`usuario`),
  ADD KEY `idx_usuario` (`usuario`),
  ADD KEY `idx_rol` (`rol`);

--
-- Indices de la tabla `ventas`
--
ALTER TABLE `ventas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cliente` (`cliente_id`),
  ADD KEY `idx_vendedor` (`vendedor_id`),
  ADD KEY `idx_estado` (`estado`),
  ADD KEY `idx_fecha` (`created_at`);

--
-- Indices de la tabla `venta_detalles`
--
ALTER TABLE `venta_detalles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_venta` (`venta_id`),
  ADD KEY `idx_articulo` (`articulo_id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `articulos`
--
ALTER TABLE `articulos`
  MODIFY `id` mediumint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT de la tabla `categorias`
--
ALTER TABLE `categorias`
  MODIFY `id` smallint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT de la tabla `clientes`
--
ALTER TABLE `clientes`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT de la tabla `provincias`
--
ALTER TABLE `provincias`
  MODIFY `id` tinyint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `stock_movimientos`
--
ALTER TABLE `stock_movimientos`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=134;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `ventas`
--
ALTER TABLE `ventas`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT de la tabla `venta_detalles`
--
ALTER TABLE `venta_detalles`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `articulos`
--
ALTER TABLE `articulos`
  ADD CONSTRAINT `fk_art_cat` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `clientes`
--
ALTER TABLE `clientes`
  ADD CONSTRAINT `fk_cli_prov` FOREIGN KEY (`provincia_id`) REFERENCES `provincias` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `stock_movimientos`
--
ALTER TABLE `stock_movimientos`
  ADD CONSTRAINT `fk_mov_art` FOREIGN KEY (`articulo_id`) REFERENCES `articulos` (`id`),
  ADD CONSTRAINT `fk_mov_usr` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`);

--
-- Filtros para la tabla `ventas`
--
ALTER TABLE `ventas`
  ADD CONSTRAINT `fk_vta_cli` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`),
  ADD CONSTRAINT `fk_vta_ven` FOREIGN KEY (`vendedor_id`) REFERENCES `usuarios` (`id`);

--
-- Filtros para la tabla `venta_detalles`
--
ALTER TABLE `venta_detalles`
  ADD CONSTRAINT `fk_det_art` FOREIGN KEY (`articulo_id`) REFERENCES `articulos` (`id`),
  ADD CONSTRAINT `fk_det_vta` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
