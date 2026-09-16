<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * Localized language
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'local' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', 'root' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',          ' S:6!gW$o7]f=U0nyDD{kL[:2(K`)I}Hg%~0x`6fSwK<3]I-`U:r]g(>z@s)uxxc' );
define( 'SECURE_AUTH_KEY',   'CA(6IG4<}{YiZxv=!~n1+e#TxLWOpKPHfH9@7QBU8dd/D{Z,!X~.wk g4llXo$/b' );
define( 'LOGGED_IN_KEY',     'I1Q9(7>$XVb0|T=aO&Q(=v8I`:3G+!=HJ_FenLzo/`k?zzNW8N;9Jvq:9P0F|ja$' );
define( 'NONCE_KEY',         '+iuR-`Y0=t#S<y6#iS=Tn}15NaHb&jTbV3It-g-9dKH!}mkPo3-Z(aJO-;a30?wX' );
define( 'AUTH_SALT',         '**n4 nCyP@xB)60.GS+?6ZptgI;8b8]c-|JWHs KIS(!UK]42J2H2e_bx]$Hl(n=' );
define( 'SECURE_AUTH_SALT',  'Cj;I` t%870sa!rCQcCsQwz|7rOAmV8I5|c9f/][APuWgt<6y-Fjma|D%}^=q%WA' );
define( 'LOGGED_IN_SALT',    '.k#r~K~;BMzvi|x7X@+TMl(Sjf*Ew{1:F_#W!TCaY$4P<5yp>h;E8lr105hc9rc}' );
define( 'NONCE_SALT',        'W:a]h!f/(Qg[NFT%c=T |?W/,[d&D(}0j8(Hu+/N~XoW`:5Co.[%y/ hbl{s]3>J' );
define( 'WP_CACHE_KEY_SALT', 'A03[^M,4T3572bo*E]wEl)hzR!Z9K)PQg)+/Y&rS8UTW:U5!$f<oV,{^OiEfomXA' );


/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';


/* Add any custom values between this line and the "stop editing" line. */



/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

define( 'WP_ENVIRONMENT_TYPE', 'local' );
/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
