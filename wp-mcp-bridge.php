<?php
/**
 * Plugin Name: WP MCP Bridge
 * Description: Endpoints REST para controlar WordPress desde Claude MCP.
 *              Usa WP-CLI si está disponible; si no, lo intenta instalar;
 *              si tampoco puede, usa PHP puro para todo.
 *              Incluye motor Divi 5 autonomous para generación de páginas
 *              (formato nativo de bloque, compatible con Divi 5).
 * Version:     1.5.8
 * Developer:   James Perez
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WP_MCP_BRIDGE_VERSION', '1.5.8' );
define( 'WP_MCP_BRIDGE_NS',      'mcp-bridge/v1' );

// Define WP_MCP_SECRET en wp-config.php:
//   define('WP_MCP_SECRET', 'tu-clave-ultra-secreta');
if ( ! defined( 'WP_MCP_SECRET' ) ) define( 'WP_MCP_SECRET', '' );

// ════════════════════════════════════════════════════════════════
// SUB-ENGINES — Defined outside main class for PHP 8.x compatibility
// ════════════════════════════════════════════════════════════════

/**
 * NLP Interpreter — parses natural language prompts into layout specs
 */
class WP_MCP_NLP_Interpreter {

    private const PAGE_TYPES = [
        'landing'   => 'landing',
        'landding'  => 'landing',
        'about'     => 'about',
        'sobre'     => 'about',
        'contact'   => 'contact',
        'contacto'  => 'contact',
        'pricing'   => 'pricing',
        'precios'   => 'pricing',
        'services'  => 'services',
        'servicios' => 'services',
        'portfolio' => 'portfolio',
    ];

    private const STYLES = [
        'moderna'    => 'modern',
        'modern'     => 'modern',
        'corporativa'=> 'corporate',
        'corporate'  => 'corporate',
        'minimal'    => 'minimal',
        'minima'     => 'minimal',
        'bold'       => 'bold',
        'elegante'   => 'elegant',
        'elegant'    => 'elegant',
    ];

    private const SECTION_KEYWORDS = [
        'hero'          => ['hero', 'header', 'cabecera', 'encabezado'],
        'services'      => ['servicios', 'services', 'iconos', 'icons', 'caracteristicas', 'features'],
        'testimonials'  => ['testimonios', 'testimonials', 'reviews', 'resenas'],
        'contact_form'  => ['formulario', 'contacto', 'contact', 'form', 'contactform'],
        'pricing'       => ['pricing', 'precios', 'planes', 'plans', 'tiers'],
        'faq'           => ['faq', 'preguntas', 'questions', 'accordion'],
        'cta'           => ['cta', 'call to action', 'boton', 'llamada', 'boton CTA'],
        'about'         => ['about', 'sobre', 'nosotros', 'team', 'equipo'],
        'footer'        => ['footer', 'pie', 'pie de pagina'],
    ];

    public function parse( string $prompt ): array {
        $prompt_lower = mb_strtolower( $prompt );

        $page_type = $this->detect_page_type( $prompt_lower );
        $style     = $this->detect_style( $prompt_lower );
        $sections  = $this->detect_sections( $prompt_lower );

        return [
            'page_type' => $page_type,
            'style'     => $style,
            'sections'  => $sections,
        ];
    }

    private function detect_page_type( string $text ): string {
        foreach ( self::PAGE_TYPES as $keyword => $type ) {
            if ( strpos( $text, $keyword ) !== false ) {
                return $type;
            }
        }
        return 'landing';
    }

    private function detect_style( string $text ): string {
        foreach ( self::STYLES as $keyword => $style ) {
            if ( strpos( $text, $keyword ) !== false ) {
                return $style;
            }
        }
        return 'modern';
    }

    private function detect_sections( string $text ): array {
        $sections = [];

        foreach ( self::SECTION_KEYWORDS as $section_type => $keywords ) {
            foreach ( $keywords as $keyword ) {
                if ( strpos( $text, $keyword ) !== false ) {
                    $spec = [ 'type' => $section_type ];

                    if ( $section_type === 'hero' ) {
                        $spec['fullwidth'] = true;
                        if ( strpos( $text, 'impactante' ) !== false || strpos( $text, 'impact' ) !== false ) {
                            $spec['impact_level'] = 'high';
                        }
                    }

                    if ( $section_type === 'services' ) {
                        if ( preg_match( '/(\d+)\s*(servicios|services|features)/', $text, $m ) ) {
                            $spec['count'] = (int) $m[1];
                        } else {
                            $spec['count'] = 3;
                        }
                        $spec['icons'] = true;
                    }

                    if ( $section_type === 'testimonials' ) {
                        $spec['layout'] = ( strpos( $text, 'carousel' ) !== false ) ? 'carousel' : 'grid';
                    }

                    $sections[] = $spec;
                    break;
                }
            }
        }

        if ( empty( $sections ) ) {
            $sections[] = [ 'type' => 'hero', 'fullwidth' => true ];
        }

        return $sections;
    }
}

/**
 * Divi 5 Block Generator — generates native Divi 5 block format
 *
 * Uses WordPress Gutenberg-style block comments instead of Divi 4 shortcodes.
 * This format is native to Divi 5 and doesn't require the compatibility layer.
 *
 * Divi 5 block format:
 * <!-- wp:divi/section {"attrs":{...}} -->
 *   <!-- wp:divi/row -->
 *     <!-- wp:divi/column {"type":{"desktop":{"value":"4_4"}}} -->
 *       <!-- wp:divi/module {"content":{"innerContent":{"desktop":{"value":"..."}}}} /-->
 *     <!-- /wp:divi/column -->
 *   <!-- /wp:divi/row -->
 * <!-- /wp:divi/section -->
 *
 * @see https://devalpha.elegantthemes.com/ — Divi 5 developer documentation
 */
class WP_MCP_Divi5_Generator {

    private $use_shortcodes = true; // This server's Divi 5.3.3 needs shortcode format

    // ════════════════════════════════════════════════════════════════
    // DESIGN TOKENS — LAIA PANAMA BRAND SYSTEM
    // ════════════════════════════════════════════════════════════════

    private const COLORS = [
        'blush'       => '#EED4D4',
        'blush-dark'  => '#D4BFBF',
        'noir'        => '#000000',
        'blanc'       => '#FFFFFF',
        'blush/40'    => 'rgba(238,212,212,0.4)',
        'blush/30'    => 'rgba(238,212,212,0.3)',
        'blush/10'    => 'rgba(238,212,212,0.1)',
    ];

    private const FONTS = [
        'font-display' => 'Playfair Display',
        'font-body'    => 'Inter',
        'font-sans'    => 'Manrope',
    ];

    private const SPACING = [
        '4'  => '16px',
        '6'  => '24px',
        '8'  => '32px',
        '12' => '48px',
        '16' => '64px',
        '20' => '80px',
        '24' => '96px',
    ];

    // Background color for common sections
    private const BG_BLUSH = 'rgba(238,212,212,0.4)';
    private const BG_BLANC = '#FFFFFF';
    private const BG_NOIR  = '#000000';

    // ════════════════════════════════════════════════════════════════
    // PHASE 3: FULL PAGE GENERATORS
    // ════════════════════════════════════════════════════════════════

    /**
     * Generate a standalone Header template for Divi Theme Builder.
     * Saved as et_template post_type so it can be assigned via Theme Builder.
     *
     * @return string Divi 5 block markup — root element is a section block
     */
    public function generate_header_template(): string {
        // Top bar: sticky, white bg, blush border-bottom
        $top_bar_col = $this->block(
            'column',
            '{"type":{"desktop":{"value":"4_4"}}}',
            $this->block(
                'text',
                '{}',
                $this->inner_content( '<div style="display:flex;align-items:center;justify-content:space-between;padding:12px 32px;background:#FFFFFF;border-bottom:1px solid rgba(238,212,212,0.4);position:sticky;top:0;z-index:999;">'
                    . '<div style="display:flex;align-items:center;gap:10px;">'
                    . '<span style="font-size:18px;color:#EED4D4;">◆</span>'
                    . '<span style="font-family:Playfair Display;font-size:16px;color:#000000;font-weight:700;letter-spacing:2px;">LAIA PANAMA</span>'
                    . '</div>'
                    . '<nav style="display:flex;gap:32px;">'
                    . '<a href="/colecciones" style="font-family:Manrope;font-size:12px;color:#000000;text-decoration:none;letter-spacing:1px;text-transform:uppercase;">Colecciones</a>'
                    . '<a href="/nuestra-historia" style="font-family:Manrope;font-size:12px;color:#000000;text-decoration:none;letter-spacing:1px;text-transform:uppercase;">Nuestra Historia</a>'
                    . '<a href="/blog" style="font-family:Manrope;font-size:12px;color:#000000;text-decoration:none;letter-spacing:1px;text-transform:uppercase;">Blog</a>'
                    . '<a href="/contacto" style="font-family:Manrope;font-size:12px;color:#000000;text-decoration:none;letter-spacing:1px;text-transform:uppercase;">Contacto</a>'
                    . '</nav>'
                    . '<div style="display:flex;align-items:center;gap:20px;">'
                    . '<a href="/buscar" style="color:#000000;font-size:16px;text-decoration:none;">🔍</a>'
                    . '<a href="/carrito" style="color:#000000;font-size:16px;text-decoration:none;">🛒</a>'
                    . '<a href="/mi-cuenta" style="font-family:Manrope;font-size:11px;color:#000000;border:1px solid #000000;padding:6px 14px;text-decoration:none;letter-spacing:1px;">Mi Cuenta</a>'
                    . '</div>'
                    . '</div>' )
            )
        );

        $top_bar_row = $this->block( 'row', '{"modulePreset":"default"}', $top_bar_col );

        return $this->block(
            'section',
            json_encode( [
                'modulePreset' => 'default',
                'background'  => [ 'desktop' => [ 'value' => [ 'color' => self::COLORS['blanc'] ] ] ],
                'padding'     => [ 'desktop' => [ 'value' => [ 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0' ] ] ],
                'layout'      => [ 'desktop' => [ 'value' => [ 'useBackground' => false ] ] ],
            ] ),
            $top_bar_row
        );
    }

    /**
     * Generate a standalone Footer template for Divi Theme Builder.
     * Saved as et_template post_type so it can be assigned via Theme Builder.
     *
     * @return string Divi 5 block markup — root element is a section block
     */
    public function generate_footer_template(): string {
        // 5-col grid inside a single column
        $footer_top_row = $this->block(
            'row',
            '{"modulePreset":"default"}',
            // Col 1: Logo + text + socials
            $this->block(
                'column',
                '{"type":{"desktop":{"value":"1_5"}}}',
                $this->block(
                    'text',
                    '{}',
                    $this->inner_content( '<div style="color:#FFFFFF;"><span style="font-size:16px;color:#EED4D4;">◆</span><span style="font-family:Playfair Display;font-size:13px;letter-spacing:2px;margin-left:8px;color:#FFFFFF;">LAIA PANAMA</span></div>'
                        . '<p style="font-family:Inter;font-size:12px;color:#999999;margin-top:12px;max-width:180px;line-height:1.6;">Redefiniendo el estándar de la alta joyería contemporánea con una visión cálida y sofisticada.</p>'
                        . '<div style="display:flex;gap:10px;margin-top:16px;">'
                        . '<a href="https://instagram.com/laiapanama" style="color:#FFFFFF;font-size:16px;text-decoration:none;">📷</a>'
                        . '<a href="https://facebook.com/laiapanama" style="color:#FFFFFF;font-size:16px;text-decoration:none;">📘</a>'
                        . '<a href="https://twitter.com/laiapanama" style="color:#FFFFFF;font-size:16px;text-decoration:none;">🐦</a>'
                        . '</div>' )
                )
            ) . "\n" .
            // Col 2: Menú Editorial
            $this->block(
                'column',
                '{"type":{"desktop":{"value":"1_5"}}}',
                $this->block(
                    'text',
                    '{}',
                    $this->inner_content( '<h4 style="font-family:Manrope;font-size:10px;color:#EED4D4;letter-spacing:2px;text-transform:uppercase;margin-bottom:16px;">Menú Editorial</h4>'
                        . '<ul style="list-style:none;padding:0;margin:0;">'
                        . '<li><a href="/nuestra-historia" style="font-family:Inter;font-size:12px;color:#999999;text-decoration:none;display:block;margin-bottom:8px;">Nuestra Filosofía</a></li>'
                        . '<li><a href="/colecciones" style="font-family:Inter;font-size:12px;color:#999999;text-decoration:none;display:block;margin-bottom:8px;">Colecciones</a></li>'
                        . '<li><a href="/laia-panama" style="font-family:Inter;font-size:12px;color:#999999;text-decoration:none;display:block;margin-bottom:8px;">LAIA PANAMA</a></li>'
                        . '<li><a href="/blog" style="font-family:Inter;font-size:12px;color:#999999;text-decoration:none;display:block;">Blog</a></li>'
                        . '</ul>' )
                )
            ) . "\n" .
            // Col 3: Atención Exclusiva
            $this->block(
                'column',
                '{"type":{"desktop":{"value":"1_5"}}}',
                $this->block(
                    'text',
                    '{}',
                    $this->inner_content( '<h4 style="font-family:Manrope;font-size:10px;color:#EED4D4;letter-spacing:2px;text-transform:uppercase;margin-bottom:16px;">Atención Exclusiva</h4>'
                        . '<ul style="list-style:none;padding:0;margin:0;">'
                        . '<li style="font-family:Inter;font-size:12px;color:#999999;margin-bottom:8px;">+507 8888-0000</li>'
                        . '<li style="font-family:Inter;font-size:12px;color:#999999;margin-bottom:8px;">info@laiapanama.com</li>'
                        . '<li style="font-family:Inter;font-size:12px;color:#999999;">Flagship Boutique, Ciudad de Panamá</li>'
                        . '</ul>' )
                )
            ) . "\n" .
            // Col 4: Servicio al Cliente
            $this->block(
                'column',
                '{"type":{"desktop":{"value":"1_5"}}}',
                $this->block(
                    'text',
                    '{}',
                    $this->inner_content( '<h4 style="font-family:Manrope;font-size:10px;color:#EED4D4;letter-spacing:2px;text-transform:uppercase;margin-bottom:16px;">Servicio al Cliente</h4>'
                        . '<ul style="list-style:none;padding:0;margin:0;">'
                        . '<li><a href="/contacto" style="font-family:Inter;font-size:12px;color:#999999;text-decoration:none;display:block;margin-bottom:8px;">Contacto</a></li>'
                        . '<li><a href="/envios" style="font-family:Inter;font-size:12px;color:#999999;text-decoration:none;display:block;margin-bottom:8px;">Envíos</a></li>'
                        . '<li><a href="/devoluciones" style="font-family:Inter;font-size:12px;color:#999999;text-decoration:none;display:block;">Devoluciones</a></li>'
                        . '</ul>' )
                )
            ) . "\n" .
            // Col 5: Suscripción Privada
            $this->block(
                'column',
                '{"type":{"desktop":{"value":"1_5"}}}',
                $this->block(
                    'text',
                    '{}',
                    $this->inner_content( '<h4 style="font-family:Manrope;font-size:10px;color:#EED4D4;letter-spacing:2px;text-transform:uppercase;margin-bottom:16px;">Suscripción Privada</h4>'
                        . '<p style="font-family:Inter;font-size:12px;color:#999999;margin-bottom:12px;">Reciba novedades sobre colecciones cápsula y eventos privados.</p>'
                        . '<div style="display:flex;gap:8px;">'
                        . '<input type="email" placeholder="Su correo" style="flex:1;padding:8px 12px;border:1px solid #333333;background:transparent;font-family:Manrope;font-size:11px;color:#FFFFFF;" />'
                        . '<button style="padding:8px 12px;background:#EED4D4;color:#000000;font-family:Manrope;font-size:11px;border:none;cursor:pointer;letter-spacing:0.5px;">→</button>'
                        . '</div>' )
                )
            )
        );

        // Bottom row: payment | copyright | legal links
        $footer_bottom_row = $this->block(
            'row',
            '{"modulePreset":"default"}',
            $this->block(
                'column',
                '{"type":{"desktop":{"value":"4_4"}}}',
                $this->block(
                    'text',
                    '{}',
                    $this->inner_content( '<div style="border-top:1px solid #333333;margin-top:40px;padding-top:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;">'
                        . '<div style="display:flex;gap:8px;align-items:center;">'
                        . '<span style="font-family:Inter;font-size:10px;color:#FFFFFF;border:1px solid #555555;padding:3px 8px;">VISA</span>'
                        . '<span style="font-family:Inter;font-size:10px;color:#FFFFFF;border:1px solid #555555;padding:3px 8px;">MC</span>'
                        . '<span style="font-family:Inter;font-size:10px;color:#FFFFFF;border:1px solid #555555;padding:3px 8px;">AMEX</span>'
                        . '</div>'
                        . '<p style="font-family:Inter;font-size:11px;color:#666666;">© 2026 LAIA PANAMA. HIGH JEWELRY & DESIGN.</p>'
                        . '<div style="display:flex;gap:16px;">'
                        . '<a href="/terminos" style="font-family:Inter;font-size:11px;color:#666666;text-decoration:none;">Términos</a>'
                        . '<a href="/privacidad" style="font-family:Inter;font-size:11px;color:#666666;text-decoration:none;">Privacidad</a>'
                        . '<a href="/cookies" style="font-family:Inter;font-size:11px;color:#666666;text-decoration:none;">Cookies</a>'
                        . '</div>'
                        . '</div>' )
                )
            )
        );

        $footer_col = $this->block(
            'column',
            '{"type":{"desktop":{"value":"4_4"}}}',
            $footer_top_row . "\n" . $footer_bottom_row
        );

        $footer_row = $this->block( 'row', '{"modulePreset":"default"}', $footer_col );

        return $this->block(
            'section',
            json_encode( [
                'modulePreset' => 'default',
                'background'  => [ 'desktop' => [ 'value' => [ 'color' => self::COLORS['noir'] ] ] ],
                'padding'     => [
                    'desktop' => [
                        'value' => [
                            'top'    => '64px',
                            'right'  => '32px',
                            'bottom' => '32px',
                            'left'   => '32px',
                        ],
                    ],
                ],
            ] ),
            $footer_row
        );
    }

    /**
     * Route page name to the appropriate body generator.
     * Header/footer are handled separately by Theme Builder.
     *
     * @param string $page Page identifier (e.g. 'inicio', 'home', 'colecciones')
     * @return string Divi 5 block markup — body sections only (no header/footer)
     */
    public function generate_body_template( string $page ): string {
        $placeholder = '<!-- wp:divi/placeholder -->';
        
        switch ( $page ) {
            case 'inicio':
            case 'home':
                return $placeholder . $this->generate_inicio_body();
            case 'colecciones':
                return $placeholder . $this->generate_colecciones_body();
            case 'contacto':
                return $placeholder . $this->generate_contacto_body();
            case 'nuestra-historia':
                return $placeholder . $this->generate_nuestra_historia_body();
            default:
                return $placeholder . $this->generate_inicio_body();
        }
    }

    /**
     * Generate Inicio page body only — no header, no footer.
     * Used by create_divi_page for page content. Header/footer
     * come from Divi Theme Builder templates.
     */
    private function generate_inicio_body(): string {
        $blocks = [];

        // ── 1. HERO ───────────────────────────────────────────────────
        $hero_left_col = $this->block(
            'column',
            json_encode( [ 'type' => '1_2' ] ),
            $this->block(
                'text',
                '{}',
                '<p style="font-family:Manrope;font-size:11px;color:#000000;letter-spacing:3px;text-transform:uppercase;margin-bottom:16px;">Haute Joaillerie</p>'
            ) . "\n" .
            $this->block(
                'heading',
                json_encode( [
                    'headingLevel' => 'h1',
                    'fontFamily'   => 'Playfair Display',
                    'textColor'    => self::COLORS['noir'],
                ] ),
                'Esencia<br>Blush Accent'
            )
        );

        $hero_right_col = $this->block(
            'column',
            json_encode( [ 'type' => '1_2' ] ),
            $this->block(
                'image',
                json_encode( [
                    'src' => 'https://images.unsplash.com/photo-1515562141207-7a88fb7ce338?w=1200&q=80',
                    'alt' => 'Joyería LAIA PANAMA',
                ] ),
                ''
            )
        );

        $hero_row = $this->block( 'row', '{}', $hero_left_col . "\n" . $hero_right_col );
        $blocks[] = $this->block(
            'section',
            json_encode( [
                'background' => self::COLORS['blanc'],
                'padding'    => [ 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0' ],
                'height'     => '90vh',
            ] ),
            $hero_row
        );

        // ── 2. COLECCIONES PRIVADAS ───────────────────────────────────
        $colecciones_images_row = $this->block(
            'row',
            '{}',
            $this->block(
                'column',
                json_encode( [ 'type' => '1_3' ] ),
                $this->block( 'image', json_encode( [ 'src' => 'https://images.unsplash.com/photo-1617038220319-276d3cfab638?w=400&q=80' ] ), '' )
            ) . "\n" .
            $this->block(
                'column',
                json_encode( [ 'type' => '1_3' ] ),
                $this->block( 'image', json_encode( [ 'src' => '' ] ), '' )
            ) . "\n" .
            $this->block(
                'column',
                json_encode( [ 'type' => '1_3' ] ),
                $this->block( 'image', json_encode( [ 'src' => '' ] ), '' )
            )
        );

        $colecciones_col = $this->block(
            'column',
            json_encode( [ 'type' => '4_4' ] ),
            $this->block(
                'text',
                '{}',
                '<h2 style="font-family:Playfair Display;font-size:32px;color:#000000;text-align:center;margin-bottom:8px;">Colecciones Privadas</h2>'
            ) . "\n" .
            $this->block(
                'text',
                '{}',
                '<p style="text-align:center;"><a href="/colecciones" style="font-family:Manrope;font-size:14px;color:#000000;border-bottom:1px solid #000000;padding-bottom:2px;text-decoration:none;">Ver toda la galería →</a></p>'
            ) . "\n" .
            $colecciones_images_row
        );

        $colecciones_row = $this->block( 'row', '{}', $colecciones_col );
        $blocks[] = $this->block(
            'section',
            json_encode( [
                'background' => self::COLORS['blush/40'],
                'padding'    => [ 'top' => '80px', 'right' => '32px', 'bottom' => '80px', 'left' => '32px' ],
            ] ),
            $colecciones_row
        );

        // ── 3. LA HERENCIA DEL LUJO MODERNO ───────────────────────────
        $herencia_left_col = $this->block(
            'column',
            json_encode( [ 'type' => '1_2' ] ),
            $this->block(
                'image',
                json_encode( [
                    'src' => 'https://images.unsplash.com/photo-1617038220319-276d3cfab638?w=800&q=80',
                    'alt' => 'Legacy & Blush Artistry',
                ] ),
                ''
            )
        );

        $herencia_right_col = $this->block(
            'column',
            json_encode( [ 'type' => '1_2' ] ),
            $this->block(
                'text',
                '{}',
                '<p style="font-family:Manrope;font-size:11px;color:#000000;letter-spacing:3px;text-transform:uppercase;margin-bottom:12px;">Legacy & Blush Artistry</p>'
            ) . "\n" .
            $this->block(
                'heading',
                json_encode( [
                    'headingLevel' => 'h2',
                    'fontFamily'   => 'Playfair Display',
                ] ),
                'La Herencia del<br>Lujo Moderno'
            )
        );

        $herencia_row = $this->block( 'row', '{}', $herencia_left_col . "\n" . $herencia_right_col );
        $blocks[] = $this->block(
            'section',
            json_encode( [
                'background' => self::COLORS['blanc'],
                'padding'    => [ 'top' => '80px', 'right' => '32px', 'bottom' => '80px', 'left' => '32px' ],
            ] ),
            $herencia_row
        );

        // ── 4. ÚNETE AL CÍRCULO EXCLUSIVO ─────────────────────────────
        $cta_col = $this->block(
            'column',
            json_encode( [ 'type' => '4_4' ] ),
            $this->block(
                'heading',
                json_encode( [
                    'headingLevel' => 'h2',
                    'textAlign'   => 'center',
                    'fontFamily'   => 'Playfair Display',
                ] ),
                'Únete al Círculo Exclusivo'
            )
        );

        $cta_row = $this->block( 'row', '{}', $cta_col );
        $blocks[] = $this->block(
            'section',
            json_encode( [
                'background' => self::COLORS['blush/30'],
                'padding'    => [ 'top' => '80px', 'right' => '32px', 'bottom' => '80px', 'left' => '32px' ],
            ] ),
            $cta_row
        );

        return implode( "\n\n", $blocks );
    }

    /**
     * Placeholder body generator for Colecciones page.
     * Full implementation to follow in next phase.
     */
    private function generate_colecciones_body(): string {
        return $this->generate_inicio_body();
    }

    /**
     * Placeholder body generator for Contacto page.
     * Full implementation to follow in next phase.
     */
    private function generate_contacto_body(): string {
        return $this->generate_inicio_body();
    }

    /**
     * Placeholder body generator for Nuestra Historia page.
     * Full implementation to follow in next phase.
     */
    private function generate_nuestra_historia_body(): string {
        return $this->generate_inicio_body();
    }

    /**
     * Generate complete page content in Divi 5 block format
     */
    public function generate_page( array $layout_spec, string $style = 'modern' ): string {
        $blocks = [];

        foreach ( $layout_spec['sections'] as $section ) {
            $blocks[] = $this->generate_section( $section, $style );
        }

        return implode( "\n\n", $blocks );
    }

    /**
     * Generate a section block (section > row > column > module)
     */
    public function generate_section( array $spec, string $style ): string {
        $type = $spec['type'] ?? 'text';

        switch ( $type ) {
            case 'hero':
                return $this->generate_hero( $spec, $style );
            case 'services':
                return $this->generate_services( $spec, $style );
            case 'testimonials':
                return $this->generate_testimonials( $spec, $style );
            case 'contact_form':
                return $this->generate_contact_form( $spec, $style );
            case 'pricing':
                return $this->generate_pricing( $spec, $style );
            case 'faq':
                return $this->generate_faq( $spec, $style );
            case 'cta':
                return $this->generate_cta( $spec, $style );
            case 'about':
                return $this->generate_about( $spec, $style );
            default:
                return $this->generate_text_section( $spec, $style );
        }
    }

    /**
     * Generate a full-width hero section
     */
    private function generate_hero( array $spec, string $style ): string {
        $bg_image = $spec['background_image'] ?? 'https://images.unsplash.com/photo-1515562141207-7a88fb7ce338?w=1200&q=80';
        $title = $spec['title'] ?? 'Bienvenido a nuestra plataforma';
        $subtitle = $spec['subtitle'] ?? 'Soluciones innovadoras para impulsar tu negocio al siguiente nivel.';
        $primary_color = $spec['primary_color'] ?? '#007bff';

        $cols = $this->block(
            'column',
            '{"type":{"desktop":{"value":"4_4"}}}',
            $this->block(
                'text',
                '{}',
                '<h1 style="font-family:Playfair Display;font-size:48px;color:#ffffff;">' . $title . '</h1>' . "\n" . '<p style="font-family:Inter;font-size:18px;color:#ffffff;">' . $subtitle . '</p>'
            ) . "\n" .
            $this->block(
                'button',
                '{"button_text":"Contáctanos","button_url":"#","alignment":{"desktop":{"value":"center"}}}',
                ''
            )
        );

        $row = $this->block( 'row', '{"modulePreset":"default"}', $cols );

        return $this->block(
            'section',
            json_encode( [
                'modulePreset'         => 'default',
                'background'          => [ 'desktop' => [ 'value' => [ 'color' => $primary_color ] ] ],
                'backgroundImage'     => [ 'desktop' => [ 'value' => [ 'image' => $bg_image ] ] ],
                'backgroundSize'      => [ 'desktop' => [ 'value' => 'cover' ] ],
                'backgroundBlendMode' => [ 'desktop' => [ 'value' => 'normal' ] ],
                'padding'             => [
                    'desktop' => [
                        'value' => [
                            'top'    => '96px',
                            'right'  => '32px',
                            'bottom' => '96px',
                            'left'   => '32px',
                        ],
                    ],
                ],
                'useBackgroundColor' => [ 'desktop' => [ 'value' => 'on' ] ],
            ] ),
            $row
        );
    }

    /**
     * Generate a services section with icon cards
     */
    private function generate_services( array $spec, string $style ): string {
        $count = $spec['count'] ?? 3;
        $cols = [];

        $col_type_map = [ 1 => '4_4', 2 => '1_2', 3 => '1_3', 4 => '1_4' ];
        $col_type = $col_type_map[ $count ] ?? '1_3';

        for ( $i = 1; $i <= $count; $i++ ) {
            $service_num = $i;
            $cols .= $this->block(
                'column',
                '{"type":{"desktop":{"value":"' . $col_type . '"}}}',
                $this->block(
                    'blurb',
                    json_encode( [
                        'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => "Servicio {$service_num}" ] ] ],
                        'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => "<p>Descripción del servicio {$service_num}. Agrega aquí los detalles de tu servicio.</p>" ] ] ],
                        'url'    => [ 'desktop' => [ 'value' => '#' ] ],
                        'image'  => [ 'desktop' => [ 'value' => [ 'image' => "https://images.unsplash.com/photo-1515562141207-7a88fb7ce338?w=100&q=80" ] ] ],
                    ] ),
                    ''
                )
            );
        }

        $row = $this->block( 'row', '{"modulePreset":"default"}', $cols );

        return $this->block(
            'section',
            json_encode( [
                'modulePreset'  => 'default',
                'background'    => [ 'desktop' => [ 'value' => [ 'color' => '#ffffff' ] ] ],
                'padding'       => [
                    'desktop' => [
                        'value' => [
                            'top'    => '64px',
                            'right'  => '32px',
                            'bottom' => '64px',
                            'left'   => '32px',
                        ],
                    ],
                ],
            ] ),
            $row
        );
    }

    /**
     * Generate a testimonials section
     */
    private function generate_testimonials( array $spec, string $style ): string {
        $cols = '';
        $testimonials = [
            [ 'name' => 'Cliente Satisfecho', 'company' => 'Empresa XYZ', 'quote' => 'Excelente servicio y atención al cliente. Muy recomendable.' ],
            [ 'name' => 'Cliente Satisfecho', 'company' => 'Empresa ABC', 'quote' => 'Los resultados superaron mis expectativas. Professionalism exemplary.' ],
        ];

        foreach ( $testimonials as $t ) {
            $cols .= $this->block(
                'column',
                '{"type":{"desktop":{"value":"1_2"}}}',
                $this->block(
                    'testimonial',
                    json_encode( [
                        'name'      => [ 'innerContent' => [ 'desktop' => [ 'value' => $t['name'] ] ] ],
                        'job_title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $t['company'] ] ] ],
                        'quote'     => [ 'innerContent' => [ 'desktop' => [ 'value' => "<p>\"{$t['quote']}\"</p>" ] ] ],
                    ] ),
                    ''
                )
            );
        }

        $row = $this->block( 'row', '{"modulePreset":"default"}', $cols );

        return $this->block(
            'section',
            json_encode( [
                'modulePreset' => 'default',
                'background'   => [ 'desktop' => [ 'value' => [ 'color' => '#f8f9fa' ] ] ],
                'padding'      => [
                    'desktop' => [
                        'value' => [
                            'top'    => '64px',
                            'right'  => '32px',
                            'bottom' => '64px',
                            'left'   => '32px',
                        ],
                    ],
                ],
            ] ),
            $row
        );
    }

    /**
     * Generate a contact form section
     */
    private function generate_contact_form( array $spec, string $style ): string {
        $cols = $this->block(
                'column',
                '{"type":{"desktop":{"value":"2_3"}}}',
                $this->block(
                    'contact_form',
                    json_encode( [
                        'title'        => [ 'innerContent' => [ 'desktop' => [ 'value' => 'Contáctanos' ] ] ],
                        'email'        => [ 'desktop' => [ 'value' => 'admin@example.com' ] ],
                        'button_text'  => [ 'innerContent' => [ 'desktop' => [ 'value' => 'Enviar mensaje' ] ] ],
                    ] ),
                    ''
                )
            ) . "\n" .
            $this->block(
                'column',
                '{"type":{"desktop":{"value":"1_3"}}}',
                $this->block(
                    'text',
                    '{}',
                    "<p><strong>Información de contacto</strong></p><p>Dirección: Calle Ejemplo 123</p><p>Teléfono: +1 234 567 890</p><p>Email: info@example.com</p>"
                )
            );

        $row = $this->block( 'row', '{"modulePreset":"default"}', $cols );

        return $this->block(
            'section',
            json_encode( [
                'modulePreset' => 'default',
                'background'   => [ 'desktop' => [ 'value' => [ 'color' => '#ffffff' ] ] ],
                'padding'      => [
                    'desktop' => [
                        'value' => [
                            'top'    => '64px',
                            'right'  => '32px',
                            'bottom' => '64px',
                            'left'   => '32px',
                        ],
                    ],
                ],
            ] ),
            $row
        );
    }

    /**
     * Generate a pricing tables section
     */
    private function generate_pricing( array $spec, string $style ): string {
        $count = $spec['count'] ?? 3;
        $cols = '';

        for ( $i = 1; $i <= $count; $i++ ) {
            $featured = $i === 2 ? 'on' : 'off';
            $cols .= $this->block(
                'column',
                '{"type":{"desktop":{"value":"1_' . $count . '"}}}',
                $this->block(
                    'pricing_table',
                    json_encode( [
                        'title'    => [ 'innerContent' => [ 'desktop' => [ 'value' => "Plan {$i}" ] ] ],
                        'currency'  => [ 'desktop' => [ 'value' => '$' ] ],
                        'price'    => [ 'innerContent' => [ 'desktop' => [ 'value' => "{$i}9" ] ] ],
                        'frequency'=> [ 'innerContent' => [ 'desktop' => [ 'value' => '/mes' ] ] ],
                        'button_text' => [ 'innerContent' => [ 'desktop' => [ 'value' => "Elegir plan {$i}" ] ] ],
                        'button_url'  => [ 'desktop' => [ 'value' => '#' ] ],
                        'featured' => [ 'desktop' => [ 'value' => $featured ] ],
                    ] ),
                    "<ul><li>Característica {$i}a</li><li>Característica {$i}b</li><li>Soporte prioritario</li></ul>"
                )
            );
        }

        $row = $this->block( 'row', '{"modulePreset":"default"}', $cols );

        return $this->block(
            'section',
            json_encode( [
                'modulePreset' => 'default',
                'background'   => [ 'desktop' => [ 'value' => [ 'color' => '#ffffff' ] ] ],
                'padding'      => [
                    'desktop' => [
                        'value' => [
                            'top'    => '64px',
                            'right'  => '32px',
                            'bottom' => '64px',
                            'left'   => '32px',
                        ],
                    ],
                ],
            ] ),
            $row
        );
    }

    /**
     * Generate an FAQ accordion section
     */
    private function generate_faq( array $spec, string $style ): string {
        $items = '';
        $faqs = [
            [ 'question' => '¿Cuáles son los tiempos de entrega?', 'answer' => 'Nuestros tiempos de entrega varían según la complejidad del proyecto. Generalmente entre 5-15 días hábiles.' ],
            [ 'question' => '¿Ofrecen soporte postventa?', 'answer' => 'Sí, todos nuestros servicios incluyen 30 días de soporte gratuito post-implementación.' ],
            [ 'question' => '¿Cómo funcionan los pagos?', 'answer' => 'Aceptamos transferencia bancaria, tarjeta de crédito y PayPal. 50% inicial, 50% a la entrega.' ],
        ];

        foreach ( $faqs as $faq ) {
            $items .= $this->block(
                'accordion_item',
                json_encode( [
                    'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $faq['question'] ] ] ],
                ] ),
                "<p>{$faq['answer']}</p>"
            );
        }

        $cols = $this->block(
                'column',
                '{"type":{"desktop":{"value":"4_4"}}}',
                $this->block( 'accordion', '{"modulePreset":"default"}', $items )
        );

        $row = $this->block( 'row', '{"modulePreset":"default"}', $cols );

        return $this->block(
            'section',
            json_encode( [
                'modulePreset' => 'default',
                'background'   => [ 'desktop' => [ 'value' => [ 'color' => '#f8f9fa' ] ] ],
                'padding'      => [
                    'desktop' => [
                        'value' => [
                            'top'    => '64px',
                            'right'  => '32px',
                            'bottom' => '64px',
                            'left'   => '32px',
                        ],
                    ],
                ],
            ] ),
            $row
        );
    }

    /**
     * Generate a CTA (Call to Action) section
     */
    private function generate_cta( array $spec, string $style ): string {
        $primary_color = $spec['primary_color'] ?? '#007bff';
        $cta_text = $spec['cta_text'] ?? '¿Listo para comenzar? ¡Contáctanos hoy mismo!';

        $cols = $this->block(
                'column',
                '{"type":{"desktop":{"value":"4_4"}}}',
                $this->block(
                    'text',
                    '{}',
                    '<h2 style="font-family:Playfair Display;font-size:32px;color:#ffffff;text-align:center;">' . $cta_text . '</h2>'
                ) . "\n" .
                $this->block(
                    'button',
                    '{"button_text":"Contactar ahora","button_url":"#","alignment":{"desktop":{"value":"center"}}}',
                    ''
                )
        );

        $row = $this->block( 'row', '{"modulePreset":"default"}', $cols );

        return $this->block(
            'section',
            json_encode( [
                'modulePreset'  => 'default',
                'background'     => [ 'desktop' => [ 'value' => [ 'color' => $primary_color ] ] ],
                'backgroundImage' => [ 'desktop' => [ 'value' => [ 'image' => '' ] ] ],
                'padding'        => [
                    'desktop' => [
                        'value' => [
                            'top'    => '80px',
                            'right'  => '32px',
                            'bottom' => '80px',
                            'left'   => '32px',
                        ],
                    ],
                ],
            ] ),
            $row
        );
    }

    /**
     * Generate an About section
     */
    private function generate_about( array $spec, string $style ): string {
        $cols = $this->block(
                'column',
                '{"type":{"desktop":{"value":"1_2"}}}',
                $this->block(
                    'image',
                    json_encode( [
                        'src'   => [ 'desktop' => [ 'value' => [ 'image' => 'https://images.unsplash.com/photo-1617038220319-276d3cfab638?w=600&q=80' ] ] ],
                        'alt'   => [ 'desktop' => [ 'value' => 'Imagen del equipo' ] ],
                    ] ),
                    ''
                )
            ) . "\n" .
            $this->block(
                'column',
                '{"type":{"desktop":{"value":"1_2"}}}',
                $this->block(
                    'text',
                    '{}',
                    "<h3>Nuestra Misión</h3><p>Proveer soluciones innovadoras que impulsen el crecimiento de nuestros clientes.</p><h3>Nuestra Visión</h3><p>Ser la empresa líder en nuestro sector, reconocida por la excelencia de nuestro servicio.</p>"
                )
        );

        $row = $this->block( 'row', '{"modulePreset":"default"}', $cols );

        return $this->block(
            'section',
            json_encode( [
                'modulePreset' => 'default',
                'background'   => [ 'desktop' => [ 'value' => [ 'color' => '#ffffff' ] ] ],
                'padding'      => [
                    'desktop' => [
                        'value' => [
                            'top'    => '64px',
                            'right'  => '32px',
                            'bottom' => '64px',
                            'left'   => '32px',
                        ],
                    ],
                ],
            ] ),
            $row
        );
}

/**
 * Design System — manages color/font/spacing presets
 */
    private function generate_text_section( array $spec, string $style ): string {
        $content = $spec['content'] ?? 'Contenido de la página';

        $cols = $this->block(
                'column',
                '{"type":{"desktop":{"value":"4_4"}}}',
                $this->block(
                    'text',
                    '{}',
                    $this->inner_content( 'content', "<p>{$content}</p>" )
                )
        );

        $row = $this->block( 'row', '{"modulePreset":"default"}', $cols );

        return $this->block(
            'section',
            json_encode( [
                'modulePreset' => 'default',
                'background'   => [ 'desktop' => [ 'value' => [ 'color' => '#ffffff' ] ] ],
                'padding'      => [
                    'desktop' => [
                        'value' => [
                            'top'    => '48px',
                            'right'  => '32px',
                            'bottom' => '48px',
                            'left'   => '32px',
                        ],
                    ],
                ],
            ] ),
            $row
        );
    }

    /**
     * Build a Divi 5 block comment wrapper
     *
     * @param string $module Module type (section, row, column, text, button, etc.)
     * @param string $attrs JSON attributes string (already encoded)
     * @param string $inner Inner content (usually child blocks or HTML)
     * @param bool   $self_close Whether this is a self-closing block
     */
    private function block( string $module, string $attrs, string $inner = '', bool $self_close = false ): string {
        // Divi 5 native block format - matches Divi Visual Builder output exactly
        $module_map = [
            'section' => 'section',
            'row' => 'row',
            'column' => 'column',
            'text' => 'text',
            // Divi 5 on this install does NOT render wp:divi/heading with innerContent attrs;
            // Visual Builder itself uses wp:divi/text with the <hN> tag embedded in the HTML.
            'heading' => 'text',
            'button' => 'button',
            'image' => 'image',
            'content' => 'content',
            'blurb' => 'blurb',
            'testimonial' => 'testimonial',
            'contact_form' => 'contact_form',
            'pricing_table' => 'pricing_table',
            'accordion' => 'accordion',
            'accordion_item' => 'accordion_item',
        ];

        $block_name = $module_map[ $module ] ?? $module;
        $attrs_arr = json_decode( $attrs, true ) ?: [];

        // Container modules (section, row, column) - use open/close tags
        $container_modules = [ 'section', 'row', 'column', 'accordion', 'accordion_item' ];
        if ( in_array( $module, $container_modules, true ) ) {
            $block_data = $this->build_container_block( $module, $attrs_arr );
            return "<!-- wp:divi/{$block_name} " . json_encode( $block_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . " -->\n{$inner}\n<!-- /wp:divi/{$block_name} -->";
        }

        // Content modules (text, heading, button) - self-closing with innerContent
        if ( ! empty( $inner ) ) {
            $block_data = $this->build_content_block( $module, $attrs_arr, $inner );
            // Use text preset for heading too (since we emit wp:divi/text)
            if ( $module === 'heading' ) {
                $block_data['modulePreset'] = [ $this->get_module_preset( 'text' ) ];
            }
            return "<!-- wp:divi/{$block_name} " . json_encode( $block_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . " /-->";
        }

        // Self-closing blocks (images, buttons without content, etc.)
        $block_data = $this->build_simple_block( $module, $attrs_arr );
        return "<!-- wp:divi/{$block_name} " . json_encode( $block_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . " /-->";
    }

    /**
     * Build container block (section, row, column) - matches Divi 5 native structure
     */
    private function build_container_block( string $module, array $attrs ): array {
        $block = [
            'module' => [
                'decoration' => [
                    'layout' => [
                        'desktop' => [
                            'value' => [
                                'display' => 'block'
                            ]
                        ]
                    ]
                ]
            ],
            'builderVersion' => '5.0.0-public-alpha.23',
            'modulePreset' => ['default']
        ];

        switch ( $module ) {
            case 'section':
                // Background color
                if ( isset( $attrs['background'] ) ) {
                    $color = $attrs['background'];
                    $block['module']['decoration']['background'] = [
                        'desktop' => [
                            'value' => [
                                'color' => $color
                            ]
                        ]
                    ];
                }
                // Padding
                if ( isset( $attrs['padding'] ) ) {
                    $block['module']['decoration']['spacing'] = [
                        'desktop' => [
                            'value' => [
                                'padding' => $attrs['padding']
                            ]
                        ]
                    ];
                }
                // Height
                if ( isset( $attrs['height'] ) ) {
                    $block['module']['decoration']['layout'] = [
                        'desktop' => [
                            'value' => [
                                'display' => 'block',
                                'max-height' => $attrs['height']
                            ]
                        ]
                    ];
                }
                // Admin label
                if ( isset( $attrs['adminLabel'] ) ) {
                    $block['module']['meta'] = [
                        'adminLabel' => [
                            'desktop' => [
                                'value' => $attrs['adminLabel']
                            ]
                        ]
                    ];
                }
                break;

            case 'row':
                if ( isset( $attrs['columnStructure'] ) ) {
                    $block['module']['advanced'] = [
                        'columnStructure' => [
                            'desktop' => [
                                'value' => $attrs['columnStructure']
                            ]
                        ]
                    ];
                }
                break;

            case 'column':
                if ( isset( $attrs['type'] ) ) {
                    $block['module']['advanced'] = [
                        'type' => [
                            'desktop' => [
                                'value' => $attrs['type']
                            ]
                        ]
                    ];
                }
                if ( isset( $attrs['padding'] ) ) {
                    $block['module']['decoration']['spacing'] = [
                        'desktop' => [
                            'value' => [
                                'padding' => $attrs['padding']
                            ]
                        ]
                    ];
                }
                break;
        }

        return $block;
    }

    /**
     * Build content block (text, heading, button) with innerContent
     *
     * IMPORTANT — Divi 5 native structure (matches Visual Builder output):
     *   text            → root-level "content" sibling of "module":
     *                     { "module": {...}, "content": { "innerContent": { "desktop": { "value": "<p>...</p>" } } } }
     *   heading         → RENDERED AS wp:divi/text with the tag embedded in the HTML.
     *                     The native wp:divi/heading block on this Divi 5 install does NOT
     *                     render inner content when content.innerContent is used as attr.
     *                     Divi Visual Builder output itself uses wp:divi/text with <h1>..</h1>
     *                     inside the innerContent, so we mirror that (the caller sets
     *                     $module === 'heading' but we emit a text block with the tag wrapped).
     *   button          → root-level "button" sibling of "module":
     *                     { "module": {...}, "button": { "innerContent": { "desktop": { "value": { "text": "Click" } } } } }
     */
    private function build_content_block( string $module, array $attrs, string $inner ): array {
        $block = [
            'module' => [
                'decoration' => [
                    'layout' => [
                        'desktop' => [
                            'value' => [
                                'display' => 'block'
                            ]
                        ]
                    ]
                ]
            ],
            'builderVersion' => '5.0.0-public-beta.1',
            'modulePreset' => [ $this->get_module_preset( $module ) ]
        ];

        if ( $module === 'button' ) {
            // Strip any surrounding tags so the button text is plain
            $btn_text = trim( wp_strip_all_tags( $inner ) );
            $block['button'] = [
                'innerContent' => [
                    'desktop' => [
                        'value' => [
                            'text' => $btn_text
                        ]
                    ]
                ]
            ];
            if ( isset( $attrs['backgroundColor'] ) || isset( $attrs['background'] ) ) {
                $bg = $attrs['backgroundColor'] ?? $attrs['background'];
                $block['button']['decoration']['background'] = [
                    'desktop' => [ 'value' => [ 'color' => $bg ] ]
                ];
            }
            if ( isset( $attrs['href'] ) || isset( $attrs['url'] ) ) {
                $href = $attrs['href'] ?? $attrs['url'];
                $block['module']['advanced']['link'] = [
                    'desktop' => [ 'value' => [ 'url' => $href ] ]
                ];
            }
            return $block;
        }

        // TEXT / HEADING — both emit as wp:divi/text with raw HTML inside innerContent
        $html_value = $inner;
        if ( $module === 'heading' ) {
            $level = isset( $attrs['headingLevel'] )
                ? strtolower( $attrs['headingLevel'] )
                : 'h2';
            // If $inner doesn't already start with a heading tag, wrap it
            if ( ! preg_match( '/^\s*<h[1-6][\s>]/i', $inner ) ) {
                $style_attrs = [];
                if ( isset( $attrs['textColor'] ) ) {
                    $style_attrs[] = 'color:' . $attrs['textColor'];
                }
                if ( isset( $attrs['fontFamily'] ) ) {
                    $style_attrs[] = 'font-family:' . $attrs['fontFamily'];
                }
                if ( isset( $attrs['textAlign'] ) ) {
                    $style_attrs[] = 'text-align:' . $attrs['textAlign'];
                }
                if ( isset( $attrs['fontSize'] ) ) {
                    $style_attrs[] = 'font-size:' . $attrs['fontSize'];
                }
                $style_attr = $style_attrs ? ' style="' . implode( ';', $style_attrs ) . '"' : '';
                $html_value = "<{$level}{$style_attr}>" . $inner . "</{$level}>";
            }
        }

        // Root-level "content" (NOT nested inside "module")
        $block['content'] = [
            'innerContent' => [
                'desktop' => [
                    'value' => $this->divi_escape_html( $html_value )
                ]
            ]
        ];

        return $block;
    }

    /**
     * Build simple block (image, etc.)
     *
     * IMPORTANT — Divi 5 native structure for images (matches Visual Builder):
     *   { "module": {...}, "image": { "innerContent": { "desktop": { "value": { "src": "...", "alt": "..." } } } } }
     * `image` lives at the root of attrs, sibling of `module` (NOT inside module.content).
     */
    private function build_simple_block( string $module, array $attrs ): array {
        $block = [
            'module' => [
                'decoration' => [
                    'layout' => [
                        'desktop' => [
                            'value' => [ 'display' => 'block' ]
                        ]
                    ]
                ]
            ],
            'builderVersion' => '5.0.0-public-beta.1',
            'modulePreset' => [ $this->get_module_preset( $module ) ]
        ];

        if ( $module === 'image' ) {
            $img_value = [];
            if ( isset( $attrs['src'] ) ) {
                $img_value['src'] = $attrs['src'];
            }
            if ( isset( $attrs['alt'] ) ) {
                $img_value['alt'] = $attrs['alt'];
            }
            $block['image'] = [
                'innerContent' => [
                    'desktop' => [ 'value' => $img_value ]
                ]
            ];

            // Border styling moves onto module.decoration
            if ( isset( $attrs['borderColor'] ) || isset( $attrs['borderWidth'] ) ) {
                $border_val = [];
                if ( isset( $attrs['borderColor'] ) ) {
                    $border_val['color'] = $attrs['borderColor'];
                }
                if ( isset( $attrs['borderWidth'] ) ) {
                    $border_val['width'] = $attrs['borderWidth'];
                }
                $block['module']['decoration']['border'] = [
                    'desktop' => [ 'value' => $border_val ]
                ];
            }

            // Sizing (maxWidth / maxHeight) goes to module.advanced.sizing
            if ( isset( $attrs['maxWidth'] ) || isset( $attrs['maxHeight'] ) ) {
                $sizing_val = [];
                if ( isset( $attrs['maxWidth'] ) ) {
                    $sizing_val['maxWidth'] = $attrs['maxWidth'];
                }
                if ( isset( $attrs['maxHeight'] ) ) {
                    $sizing_val['maxHeight'] = $attrs['maxHeight'];
                }
                $block['module']['advanced']['sizing'] = [
                    'desktop' => [ 'value' => $sizing_val ]
                ];
            }
        }

        // Button without inner text (uses attrs.text)
        if ( $module === 'button' ) {
            $btn_text = $attrs['text'] ?? $attrs['buttonText'] ?? 'Click Here';
            $block['button'] = [
                'innerContent' => [
                    'desktop' => [ 'value' => [ 'text' => $btn_text ] ]
                ]
            ];
            if ( isset( $attrs['backgroundColor'] ) || isset( $attrs['background'] ) ) {
                $bg = $attrs['backgroundColor'] ?? $attrs['background'];
                $block['button']['decoration']['background'] = [
                    'desktop' => [ 'value' => [ 'color' => $bg ] ]
                ];
            }
            if ( isset( $attrs['href'] ) || isset( $attrs['url'] ) ) {
                $href = $attrs['href'] ?? $attrs['url'];
                $block['module']['advanced']['link'] = [
                    'desktop' => [ 'value' => [ 'url' => $href ] ]
                ];
            }
        }

        return $block;
    }

    /**
     * Get module preset ID for Divi 5 (as array)
     */
    private function get_module_preset( string $module ): string {
        $presets = [
            'text' => '8e7276dd-055b-4333-a093-24a9d601e28c',
            'heading' => '022a091e-90ec-4e15-a17b-4dfe0849076f',
            'button' => 'c6973ce4-4f04-46d2-9832-da6eba76bab2',
            'image' => 'default',
            'blurb' => 'default',
            'testimonial' => 'default',
            'contact_form' => 'default',
            'pricing_table' => 'default',
        ];
        return $presets[ $module ] ?? 'default';
    }

    /**
     * Escape HTML for Divi 5 innerContent (convert < to \u003c, > to \u003e)
     */
    private function divi_escape_html( string $html ): string {
        return str_replace(
            [ '<', '>', '"' ],
            [ '\u003c', '\u003e', '\\"' ],
            $html
        );
    }

    /**
     * Return raw HTML for use as inner content of a parent block.
     *
     * @param string $value The HTML content
     */
    private function inner_content( string $value ): string {
        return $value;
    }

    /**
     * Escape special characters in JSON string values
     */
    private function escape_json_value( string $value ): string {
        return addcslashes( $value, '\\"' );
    }

    // ════════════════════════════════════════════════════════════════
    // PHASE 2: CORE ENGINE — Source Fetch + CSS Parser + Node Mapper
    // ════════════════════════════════════════════════════════════════

    /**
     * Fetch page from dev.laiapanama.com and extract structured data
     *
     * @param string $path URL path (e.g. '/', '/inicio', '/colecciones')
     * @return array{ sections: array, images: array, text: array, error?: string }
     */
    public function generate_from_source( string $path ): array {
        $url = 'https://dev.laiapanama.com' . rtrim( $path, '/' );
        $response = wp_remote_get( $url, [ 'timeout' => 30 ] );

        if ( is_wp_error( $response ) ) {
            return [ 'error' => $response->get_error_message() ];
        }

        $html = wp_remote_retrieve_body( $response );
        if ( empty( $html ) ) {
            return [ 'error' => 'Empty response from ' . $url ];
        }

        libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        $dom->loadHTML( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();

        $xpath = new DOMXPath( $dom );
        return $this->extract_page_data( $dom, $xpath );
    }

    /**
     * Extract structured data from the DOM
     */
    private function extract_page_data( DOMDocument $dom, DOMXPath $xpath ): array {
        $data = [
            'sections' => [],
            'images'  => [],
            'text'    => [],
        ];

        // Extract all <section> elements
        $sections = $xpath->query( '//section' );
        foreach ( $sections as $section ) {
            $data['sections'][] = $this->extract_section_data( $section, $xpath );
        }

        // Extract images
        $images = $xpath->query( '//img' );
        foreach ( $images as $img ) {
            $data['images'][] = [
                'src'    => $img->getAttribute( 'src' ),
                'alt'    => $img->getAttribute( 'alt' ),
                'class'  => $img->getAttribute( 'class' ),
                'width'  => $img->getAttribute( 'width' ),
                'height' => $img->getAttribute( 'height' ),
            ];
        }

        // Extract headings and paragraphs
        $headings = $xpath->query( '//h1 | //h2 | //h3' );
        foreach ( $headings as $h ) {
            $data['text'][] = [
                'tag'   => $h->nodeName,
                'text'  => trim( $h->textContent ),
                'class' => $h->getAttribute( 'class' ),
            ];
        }

        $paragraphs = $xpath->query( '//p' );
        foreach ( $paragraphs as $p ) {
            $text = trim( $p->textContent );
            if ( ! empty( $text ) ) {
                $data['text'][] = [
                    'tag'   => 'p',
                    'text'  => $text,
                    'class' => $p->getAttribute( 'class' ),
                ];
            }
        }

        // Extract buttons
        $buttons = $xpath->query( '//a[contains(@class,"btn") or contains(@class,"button")] | //button' );
        foreach ( $buttons as $btn ) {
            $data['text'][] = [
                'tag'   => 'button',
                'text'  => trim( $btn->textContent ),
                'href'  => $btn->getAttribute( 'href' ),
                'class' => $btn->getAttribute( 'class' ),
            ];
        }

        return $data;
    }

    /**
     * Extract data from a single section element
     */
    private function extract_section_data( DOMElement $section, DOMXPath $xpath ): array {
        $result = [
            'class' => $section->getAttribute( 'class' ),
            'id'    => $section->getAttribute( 'id' ),
            'style' => $section->getAttribute( 'style' ),
            'children' => [],
        ];

        // Get direct child elements
        foreach ( $section->childNodes as $child ) {
            if ( $child->nodeType === XML_ELEMENT_NODE ) {
                $result['children'][] = [
                    'tag'  => $child->tagName,
                    'text' => trim( $child->textContent ),
                    'href' => $child->getAttribute( 'href' ),
                    'src'  => $child->getAttribute( 'src' ),
                    'alt'  => $child->getAttribute( 'alt' ),
                    'class' => $child->getAttribute( 'class' ),
                ];
            }
        }

        return $result;
    }

    /**
     * Parse a Tailwind-like design class string → Divi 5 attrs
     *
     * Examples:
     *   'bg-blush'         → background color #EED4D4
     *   'text-noir'        → text color #000000
     *   'py-24'           → padding top/bottom 96px
     *   'px-8'            → padding left/right 32px
     *   'border-blush/30' → border color rgba(238,212,212,0.3)
     *   'font-display'    → font-family Playfair Display
     *   'text-xl'         → font-size extra large
     *   'font-bold'       → font-weight bold
     *
     * @param string $class One or more space-separated class names
     * @return array Divi 5 attrs compatible array
     */
    public function parse_design_class( string $class ): array {
        $attrs = [];
        $classes = preg_split( '/\s+/', trim( $class ) );

        foreach ( $classes as $c ) {
            // Background color
            if ( preg_match( '/^bg-(blush|blush-dark|noir|blanc|blush\/[0-9]+)$/', $c, $m ) ) {
                $color_key = str_replace( 'bg-', '', $c );
                if ( isset( self::COLORS[ $color_key ] ) ) {
                    $attrs['background'] = [
                        'desktop' => [
                            'value' => [
                                'color' => self::COLORS[ $color_key ],
                            ],
                        ],
                    ];
                    $attrs['useBackgroundColor'] = [ 'desktop' => [ 'value' => 'on' ] ];
                }
                continue;
            }

            // Text color
            if ( preg_match( '/^text-(blush|blush-dark|noir|blanc|blush\/[0-9]+)$/', $c, $m ) ) {
                $color_key = str_replace( 'text-', '', $c );
                if ( isset( self::COLORS[ $color_key ] ) ) {
                    $attrs['textColor'] = [ 'desktop' => [ 'value' => self::COLORS[ $color_key ] ] ];
                }
                continue;
            }

            // Border color
            if ( preg_match( '/^border-(blush|blush-dark|noir|blanc|blush\/[0-9]+)$/', $c, $m ) ) {
                $color_key = str_replace( 'border-', '', $c );
                if ( isset( self::COLORS[ $color_key ] ) ) {
                    $attrs['borderColor'] = [ 'desktop' => [ 'value' => self::COLORS[ $color_key ] ] ];
                }
                continue;
            }

            // Padding Y (vertical)
            if ( preg_match( '/^py-(\d+)$/', $c, $m ) ) {
                $px = isset( self::SPACING[ $m[1] ] ) ? self::SPACING[ $m[1] ] : ( (int) $m[1] * 4 ) . 'px';
                $attrs['padding'] = [
                    'desktop' => [
                        'value' => [
                            'top'    => $px,
                            'bottom' => $px,
                        ],
                    ],
                ];
                continue;
            }

            // Padding X (horizontal)
            if ( preg_match( '/^px-(\d+)$/', $c, $m ) ) {
                $px = isset( self::SPACING[ $m[1] ] ) ? self::SPACING[ $m[1] ] : ( (int) $m[1] * 4 ) . 'px';
                if ( ! isset( $attrs['padding'] ) ) {
                    $attrs['padding'] = [ 'desktop' => [ 'value' => [] ] ];
                }
                if ( ! isset( $attrs['padding']['desktop']['value']['top'] ) ) {
                    $attrs['padding']['desktop']['value'] = array_merge(
                        [ 'top' => '0px', 'bottom' => '0px' ],
                        $attrs['padding']['desktop']['value']
                    );
                }
                $attrs['padding']['desktop']['value']['left']  = $px;
                $attrs['padding']['desktop']['value']['right'] = $px;
                continue;
            }

            // Margin
            if ( preg_match( '/^mt-(\d+)$/', $c, $m ) ) {
                $px = isset( self::SPACING[ $m[1] ] ) ? self::SPACING[ $m[1] ] : ( (int) $m[1] * 4 ) . 'px';
                $attrs['margin'] = [
                    'desktop' => [
                        'value' => [
                            'top' => $px,
                        ],
                    ],
                ];
                continue;
            }
            if ( preg_match( '/^mb-(\d+)$/', $c, $m ) ) {
                $px = isset( self::SPACING[ $m[1] ] ) ? self::SPACING[ $m[1] ] : ( (int) $m[1] * 4 ) . 'px';
                if ( ! isset( $attrs['margin'] ) ) {
                    $attrs['margin'] = [ 'desktop' => [ 'value' => [] ] ];
                }
                $attrs['margin']['desktop']['value']['bottom'] = $px;
                continue;
            }

            // Gap (grid gap)
            if ( preg_match( '/^gap-(\d+)$/', $c, $m ) ) {
                $px = isset( self::SPACING[ $m[1] ] ) ? self::SPACING[ $m[1] ] : ( (int) $m[1] * 4 ) . 'px';
                $attrs['columnGap'] = [ 'desktop' => [ 'value' => $px ] ];
                $attrs['gutter']   = [ 'desktop' => [ 'value' => $px ] ];
                continue;
            }

            // Grid columns
            if ( preg_match( '/^grid-cols-(\d+)$/', $c, $m ) ) {
                $cols = (int) $m[1];
                $type_map = [
                    1 => '4_4',
                    2 => '1_2',
                    3 => '1_3',
                    4 => '1_4',
                    5 => '1_5',
                    6 => '1_6',
                ];
                $col_type = $type_map[ $cols ] ?? '1_' . $cols;
                $attrs['type'] = [ 'desktop' => [ 'value' => $col_type ] ];
                continue;
            }

            // Font family
            if ( preg_match( '/^font-(display|body|sans)$/', $c, $m ) ) {
                $font_key = 'font-' . $m[1];
                if ( isset( self::FONTS[ $font_key ] ) ) {
                    $attrs['fontFamily'] = [ 'desktop' => [ 'value' => self::FONTS[ $font_key ] ] ];
                }
                continue;
            }

            // Font weight
            if ( preg_match( '/^font-(bold|normal|light|semibold)$/', $c, $m ) ) {
                $weights = [ 'bold' => '700', 'normal' => '400', 'light' => '300', 'semibold' => '600' ];
                $attrs['fontWeight'] = [ 'desktop' => [ 'value' => $weights[ $m[1] ] ?? '400' ] ];
                continue;
            }

            // Text align
            if ( preg_match( '/^text-(left|center|right)$/', $c, $m ) ) {
                $align_map = [ 'left' => 'left', 'center' => 'center', 'right' => 'right' ];
                $attrs['textAlign'] = [ 'desktop' => [ 'value' => $align_map[ $m[1] ] ?? 'left' ] ];
                continue;
            }
        }

        return $attrs;
    }

    /**
     * Map an HTML DOM element to a Divi 5 module block
     *
     * @param DOMElement $node The HTML element
     * @param array $attrs Pre-parsed design attrs from parse_design_class()
     * @return string Divi 5 block comment string
     */
    public function map_node_to_module( DOMElement $node, array $attrs = [] ): string {
        $tag = strtolower( $node->tagName );
        $attrs_json = ! empty( $attrs ) ? json_encode( $attrs ) : '{}';

        switch ( $tag ) {
            case 'section':
                // A section wraps a row with columns
                $inner = $this->map_container_children( $node );
                return $this->block(
                    'section',
                    $attrs_json,
                    $inner
                );

            case 'div':
                // Check for grid pattern
                if ( strpos( $node->getAttribute( 'class' ), 'grid' ) !== false ) {
                    return $this->map_grid_to_row( $node, $attrs );
                }
                return $this->block( 'text', $attrs_json, html_entity_decode( $node->textContent ) );

            case 'img':
                return $this->block(
                    'image',
                    json_encode( [
                        'src'  => [ 'desktop' => [ 'value' => [ 'image' => $node->getAttribute( 'src' ) ] ] ],
                        'alt'  => [ 'desktop' => [ 'value' => $node->getAttribute( 'alt' ) ] ],
                    ] ),
                    ''
                );

            case 'h1':
                return $this->block(
                    'heading',
                    json_encode( [
                        'headingLevel' => 'h1',
                    ] ),
                    trim( $node->textContent )
                );

            case 'h2':
                return $this->block(
                    'heading',
                    json_encode( [
                        'headingLevel' => 'h2',
                    ] ),
                    trim( $node->textContent )
                );

            case 'h3':
                return $this->block(
                    'heading',
                    json_encode( [
                        'headingLevel' => 'h3',
                    ] ),
                    trim( $node->textContent )
                );

            case 'p':
                return $this->block(
                    'text',
                    $attrs_json,
                    $this->inner_content( '<p>' . trim( $node->textContent ) . '</p>' )
                );

            case 'a':
                $href = $node->getAttribute( 'href' );
                $text = trim( $node->textContent );
                // If it looks like a CTA button
                if ( strpos( $node->getAttribute( 'class' ), 'btn' ) !== false ) {
                    return $this->block(
                        'button',
                        json_encode( [
                            'buttonText' => $text,
                            'buttonUrl'  => [ 'desktop' => [ 'value' => $href ] ],
                        ] ),
                        ''
                    );
                }
                // Regular link → text with link
                return $this->block(
                    'text',
                    $attrs_json,
                    $this->inner_content( '<a href="' . esc_attr( $href ) . '">' . esc_html( $text ) . '</a>' )
                );

            case 'button':
                return $this->block(
                    'button',
                    json_encode( [
                        'buttonText' => trim( $node->textContent ),
                        'buttonUrl'  => [ 'desktop' => [ 'value' => '#' ] ],
                    ] ),
                    ''
                );

            case 'form':
                return $this->block(
                    'contact_form',
                    json_encode( [
                        'title'       => [ 'innerContent' => [ 'desktop' => [ 'value' => 'Contact Us' ] ] ],
                        'button_text' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'Submit' ] ] ],
                    ] ),
                    ''
                );

            case 'nav':
                // Navigation → Divi Blurb (simplified)
                return $this->block(
                    'blurb',
                    json_encode( [
                        'title'   => [ 'innerContent' => [ 'desktop' => [ 'value' => trim( $node->textContent ) ] ] ],
                        'url'     => [ 'desktop' => [ 'value' => '#' ] ],
                    ] ),
                    ''
                );

            case 'footer':
                return $this->block(
                    'text',
                    $attrs_json,
                    $this->inner_content( '<footer>' . trim( $node->textContent ) . '</footer>' )
                );

            default:
                return $this->block(
                    'text',
                    $attrs_json,
                    $this->inner_content( '<p>' . trim( $node->textContent ) . '</p>' )
                );
        }
    }

    /**
     * Map a grid container's children to a Row + Columns structure
     */
    private function map_grid_to_row( DOMElement $node, array $parent_attrs ): string {
        $columns = [];
        $col_count = 0;

        foreach ( $node->childNodes as $child ) {
            if ( $child->nodeType !== XML_ELEMENT_NODE ) {
                continue;
            }
            $col_count++;
            $col_attrs = $this->parse_design_class( $child->getAttribute( 'class' ) );

            // Check child tag to determine module type
            $inner = '';
            if ( $child->tagName === 'img' ) {
                $inner = $this->map_node_to_module( $child, [] );
            } else {
                $inner = $this->map_node_to_module( $child, $col_attrs );
            }

            // Determine column type based on count
            $type_map = [
                1 => '4_4', 2 => '1_2', 3 => '1_3', 4 => '1_4',
                5 => '1_5', 6 => '1_6',
            ];
            $col_type = $type_map[ $col_count ] ?? '1_' . $col_count;
            $col_attrs_json = json_encode( [ 'type' => [ 'desktop' => [ 'value' => $col_type ] ] ] );

            $columns[] = $this->block( 'column', $col_attrs_json, $inner );
        }

        // Get gap from parent attrs
        $gap = $parent_attrs['columnGap']['desktop']['value'] ?? '32px';

        return $this->block(
            'row',
            json_encode( [ 'gutter' => [ 'desktop' => [ 'value' => $gap ] ] ] ),
            implode( "\n", $columns )
        );
    }

    /**
     * Map direct children of a section to column content
     */
    private function map_container_children( DOMElement $node ): string {
        $col_content = '';

        foreach ( $node->childNodes as $child ) {
            if ( $child->nodeType !== XML_ELEMENT_NODE ) {
                continue;
            }

            $class = $child->getAttribute( 'class' );
            $attrs = $this->parse_design_class( $class );
            $col_content .= $this->map_node_to_module( $child, $attrs ) . "\n";
        }

        // Wrap in a single column
        $col = $this->block( 'column', '{"type":{"desktop":{"value":"4_4"}}}', trim( $col_content ) );
        $row = $this->block( 'row', '{"modulePreset":"default"}', $col );

        return $row;
    }

    /**
     * Validate generated Divi 5 blocks for structural integrity
     *
     * @param array $blocks Array of block strings
     * @return array{ status: 'valid'|'invalid', errors: string[] }
     */
    public function validate_output( array $blocks ): array {
        $errors = [];

        if ( empty( $blocks ) ) {
            $errors[] = 'No blocks generated';
            return [ 'status' => 'invalid', 'errors' => $errors ];
        }

        foreach ( $blocks as $idx => $block ) {
            if ( ! is_string( $block ) || empty( trim( $block ) ) ) {
                $errors[] = "Block {$idx} is empty";
                continue;
            }

            // Check block has opening and closing comments
            if ( strpos( $block, '<!-- wp:divi/' ) === false ) {
                $errors[] = "Block {$idx} missing Divi 5 format marker";
            }

            // Check for placeholder text
            $placeholder_terms = [ 'Bienvenido', 'Servicio 1', 'Contenido de la página', 'Texto de ejemplo' ];
            foreach ( $placeholder_terms as $ph ) {
                if ( strpos( $block, $ph ) !== false ) {
                    $errors[] = "Block {$idx} contains placeholder text: '{$ph}'";
                }
            }
        }

        // Check sections exist
        $section_count = 0;
        foreach ( $blocks as $b ) {
            $section_count += substr_count( $b, '<!-- wp:divi/section' );
        }
        if ( $section_count === 0 ) {
            $errors[] = 'No section blocks found';
        }

        return [
            'status' => empty( $errors ) ? 'valid' : 'invalid',
            'errors' => $errors,
        ];
    }

    // ════════════════════════════════════════════════════════════════
    // PHASE 3: INICIO PAGE BUILDER
    // ════════════════════════════════════════════════════════════════

    /**
     * Generate the Inicio (home) page matching dev.laiapanama.com exactly
     *
     * Section structure:
     * 1. Header — sticky top bar, white bg, logo + nav + icons
     * 2. Hero — 90vh, white bg, right image (blush border-left), left text + buttons
     * 3. Colecciones Privadas — blush bg, heading + link, 3-col placeholder grid
     * 4. La Herencia del Lujo Moderno — 2-col, left framed image, right text + button
     * 5. Newsletter — light blush bg, centered form
     * 6. Footer — black bg, 5-col with all menus + payment icons
     */
    public function generate_inicio_page(): string {
        $blocks = [];

        // ── 1. HEADER ──────────────────────────────────────────────────
        $header_inner = $this->block(
            'column',
            '{"type":{"desktop":{"value":"4_4"}}}',
            $this->block(
                'text',
                '{}',
                $this->inner_content( '<div style="display:flex;align-items:center;justify-content:space-between;padding:16px 32px;background:#FFFFFF;border-bottom:1px solid rgba(238,212,212,0.4);position:sticky;top:0;z-index:999;">'
                    . '<div style="display:flex;align-items:center;gap:12px;">'
                    . '<span style="font-size:20px;color:#000;font-weight:700;">◆</span>'
                    . '<span style="font-family:Playfair Display;font-size:18px;color:#000;font-weight:700;letter-spacing:2px;">LAIA PANAMA</span>'
                    . '</div>'
                    . '<nav style="display:flex;gap:32px;">'
                    . '<a href="/colecciones" style="font-family:Manrope;font-size:14px;color:#000;text-decoration:none;letter-spacing:1px;">Colecciones</a>'
                    . '</nav>'
                    . '<div style="display:flex;align-items:center;gap:16px;">'
                    . '<span>🔍</span>'
                    . '<span>🛒</span>'
                    . '<a href="/login" style="font-family:Manrope;font-size:12px;color:#000;border:1px solid #000;padding:8px 16px;text-decoration:none;letter-spacing:1px;">Mi Cuenta</a>'
                    . '</div>'
                    . '</div>' )
            )
        );
        $header_row = $this->block( 'row', '{"modulePreset":"default"}', $header_inner );
        $blocks[] = $this->block(
            'section',
            json_encode( [
                'modulePreset'  => 'default',
                'background'    => [ 'desktop' => [ 'value' => [ 'color' => self::COLORS['blanc'] ] ] ],
                'padding'       => [ 'desktop' => [ 'value' => [ 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0' ] ] ],
                'position'      => [ 'desktop' => [ 'value' => 'above' ] ],
            ] ),
            $header_row
        );

        // ── 2. HERO ───────────────────────────────────────────────────
        // Left column: badge + h1 + paragraphs + 2 buttons
        // Right column: image with blush left border
        $hero_left_col = $this->block(
            'column',
            '{"type":{"desktop":{"value":"1_2"}}}',
            $this->block(
                'text',
                '{}',
                $this->inner_content( '<p style="font-family:Manrope;font-size:11px;color:#000;letter-spacing:3px;text-transform:uppercase;margin-bottom:16px;">Haute Joaillerie</p>' )
            ) . "\n" .
            $this->block(
                'heading',
                json_encode( [
                    'headingLevel' => 'h1',
                    'fontFamily'   => [ 'desktop' => [ 'value' => 'Playfair Display' ] ],
                    'textColor'    => [ 'desktop' => [ 'value' => self::COLORS['noir'] ] ],
                ] ),
                'Esencia<br>Blush Accent'
            ) . "\n" .
            $this->block(
                'text',
                '{}',
                $this->inner_content( '<p style="font-family:Inter;font-size:16px;color:#333;line-height:1.7;max-width:420px;margin-top:24px;">La calidez del rosado suave se encuentra con la pureza del diseño contemporáneo en nuestra nueva selección editorial.</p>' )
            ) . "\n" .
            $this->block(
                'button',
                json_encode( [
                    'buttonText' => 'Explorar Ahora',
                    'buttonUrl'  => [ 'desktop' => [ 'value' => '/colecciones' ] ],
                ] ),
                ''
            ) . "\n" .
            $this->block(
                'button',
                json_encode( [
                    'buttonText' => 'Ver Catálogo',
                    'buttonUrl'  => [ 'desktop' => [ 'value' => '/colecciones' ] ],
                ] ),
                ''
            )
        );

        $hero_right_col = $this->block(
            'column',
            '{"type":{"desktop":{"value":"1_2"}}}',
            $this->block(
                'image',
                json_encode( [
                    'src'      => [ 'desktop' => [ 'value' => [ 'image' => 'https://images.unsplash.com/photo-1515562141207-7a88fb7ce338?w=1200&q=80' ] ] ],
                    'alt'      => [ 'desktop' => [ 'value' => 'Joyería LAIA PANAMA' ] ],
                    'borderColor' => [ 'desktop' => [ 'value' => self::COLORS['blush'] ] ],
                    'borderWidth' => [ 'desktop' => [ 'value' => [ 'left' => '16px' ] ] ],
                ] ),
                ''
            )
        );

        $hero_row = $this->block( 'row', '{"modulePreset":"default"}', $hero_left_col . "\n" . $hero_right_col );
        $blocks[] = $this->block(
            'section',
            json_encode( [
                'modulePreset' => 'default',
                'background'   => [ 'desktop' => [ 'value' => [ 'color' => self::COLORS['blanc'] ] ] ],
                'padding'      => [
                    'desktop' => [
                        'value' => [
                            'top'    => '0',
                            'right'  => '0',
                            'bottom' => '0',
                            'left'   => '0',
                        ],
                    ],
                ],
                'height'       => [ 'desktop' => [ 'value' => '90vh' ] ],
            ] ),
            $hero_row
        );

        // ── 3. COLECCIONES PRIVADAS ───────────────────────────────────
        $colecciones_col = $this->block(
            'column',
            '{"type":{"desktop":{"value":"4_4"}}}',
            $this->block(
                'text',
                '{}',
                $this->inner_content( '<h2 style="font-family:Playfair Display;font-size:32px;color:#000;text-align:center;margin-bottom:8px;">Colecciones Privadas</h2>' )
            ) . "\n" .
            $this->block(
                'text',
                '{}',
                $this->inner_content( '<p style="text-align:center;"><a href="/colecciones" style="font-family:Manrope;font-size:14px;color:#000;border-bottom:1px solid #000;padding-bottom:2px;text-decoration:none;">Ver toda la galería →</a></p>' )
            ) . "\n" .
            // 3-col placeholder grid
            $this->block( 'row', '{"modulePreset":"default"}',
                $this->block( 'column', '{"type":{"desktop":{"value":"1_3"}}}',
                    $this->block( 'image', json_encode( [ 'src' => [ 'desktop' => [ 'value' => [ 'image' => 'https://images.unsplash.com/photo-1617038220319-276d3cfab638?w=400&q=80' ] ] ] ] ), '' )
                ) . "\n" .
                $this->block( 'column', '{"type":{"desktop":{"value":"1_3"}}}',
                    $this->block( 'image', json_encode( [ 'src' => [ 'desktop' => [ 'value' => [ 'image' => '' ] ] ] ] ), '' )
                ) . "\n" .
                $this->block( 'column', '{"type":{"desktop":{"value":"1_3"}}}',
                    $this->block( 'image', json_encode( [ 'src' => [ 'desktop' => [ 'value' => [ 'image' => '' ] ] ] ] ), '' )
                )
            )
        );

        $colecciones_row = $this->block( 'row', '{"modulePreset":"default"}', $colecciones_col );
        $blocks[] = $this->block(
            'section',
            json_encode( [
                'modulePreset' => 'default',
                'background'  => [ 'desktop' => [ 'value' => [ 'color' => self::COLORS['blush/40'] ] ] ],
                'padding'     => [
                    'desktop' => [
                        'value' => [
                            'top'    => '80px',
                            'right'  => '32px',
                            'bottom' => '80px',
                            'left'   => '32px',
                        ],
                    ],
                ],
            ] ),
            $colecciones_row
        );

        // ── 4. LA HERENCIA DEL LUJO MODERNO ───────────────────────────
        // Left: image with blush border frame
        $herencia_left_col = $this->block(
            'column',
            '{"type":{"desktop":{"value":"1_2"}}}',
            $this->block(
                'image',
                json_encode( [
                    'src'        => [ 'desktop' => [ 'value' => [ 'image' => 'https://images.unsplash.com/photo-1617038220319-276d3cfab638?w=800&q=80' ] ] ],
                    'alt'        => [ 'desktop' => [ 'value' => 'Legacy & Blush Artistry' ] ],
                    'borderColor' => [ 'desktop' => [ 'value' => self::COLORS['blush-dark'] ] ],
                    'borderWidth' => [ 'desktop' => [ 'value' => [ 'left' => '16px' ] ] ],
                ] ),
                ''
            )
        );

        // Right: label + h2 + 2 paragraphs + button with arrow
        $herencia_right_col = $this->block(
            'column',
            '{"type":{"desktop":{"value":"1_2"}}}',
            $this->block(
                'text',
                '{}',
                $this->inner_content( '<p style="font-family:Manrope;font-size:11px;color:#000;letter-spacing:3px;text-transform:uppercase;margin-bottom:12px;">Legacy & Blush Artistry</p>' )
            ) . "\n" .
            $this->block(
                'heading',
                json_encode( [
                    'headingLevel' => 'h2',
                    'fontFamily'   => [ 'desktop' => [ 'value' => 'Playfair Display' ] ],
                ] ),
                'La Herencia del<br>Lujo Moderno'
            ) . "\n" .
            $this->block(
                'text',
                '{}',
                $this->inner_content( '<p style="font-family:Inter;font-size:15px;color:#333;line-height:1.8;margin-top:20px;">Cada pieza es una manifestación de rigor artesanal envuelta en la suavidad de nuestros tonos característicos. No creamos accesorios; forjamos símbolos de elegancia perpetua.</p>' )
            ) . "\n" .
            $this->block(
                'text',
                '{}',
                $this->inner_content( '<p style="font-family:Inter;font-size:15px;color:#333;line-height:1.8;">Uso exclusivo de materiales éticos y gemas con certificación de origen. Un compromiso con la belleza que suaviza el rigor del diseño tradicional.</p>' )
            ) . "\n" .
            $this->block(
                'button',
                json_encode( [
                    'buttonText' => 'Descubra Nuestra Historia  →',
                    'buttonUrl'  => [ 'desktop' => [ 'value' => '/nuestra-historia' ] ],
                ] ),
                ''
            )
        );

        $herencia_row = $this->block( 'row', '{"modulePreset":"default"}', $herencia_left_col . "\n" . $herencia_right_col );
        $blocks[] = $this->block(
            'section',
            json_encode( [
                'modulePreset' => 'default',
                'background'   => [ 'desktop' => [ 'value' => [ 'color' => self::COLORS['blanc'] ] ] ],
                'padding'     => [
                    'desktop' => [
                        'value' => [
                            'top'    => '80px',
                            'right'  => '32px',
                            'bottom' => '80px',
                            'left'   => '32px',
                        ],
                    ],
                ],
            ] ),
            $herencia_row
        );

        // ── 5. NEWSLETTER ─────────────────────────────────────────────
        $newsletter_col = $this->block(
            'column',
            '{"type":{"desktop":{"value":"4_4"}}}',
            $this->block(
                'heading',
                json_encode( [
                    'headingLevel' => 'h2',
                    'textAlign'    => [ 'desktop' => [ 'value' => 'center' ] ],
                    'fontFamily'   => [ 'desktop' => [ 'value' => 'Playfair Display' ] ],
                ] ),
                'Únete al Círculo Exclusivo'
            ) . "\n" .
            $this->block(
                'text',
                '{}',
                $this->inner_content( '<p style="font-family:Inter;font-size:15px;color:#333;text-align:center;max-width:500px;margin:16px auto 32px;">Suscríbase para recibir crónicas sobre alta joyería, primicias de colecciones y eventos privados directamente en su bandeja de entrada.</p>' )
            ) . "\n" .
            $this->block(
                'text',
                '{}',
                $this->inner_content( '<div style="display:flex;gap:12px;justify-content:center;max-width:500px;margin:0 auto;">'
                    . '<input type="email" placeholder="Su correo electrónico" style="flex:1;padding:12px 16px;border:1px solid #D4BFBF;font-family:Manrope;font-size:14px;" />'
                    . '<button style="padding:12px 24px;background:#000;color:#fff;font-family:Manrope;font-size:14px;border:none;cursor:pointer;letter-spacing:1px;">Suscribirse</button>'
                    . '</div>' )
            )
        );

        $newsletter_row = $this->block( 'row', '{"modulePreset":"default"}', $newsletter_col );
        $blocks[] = $this->block(
            'section',
            json_encode( [
                'modulePreset' => 'default',
                'background'  => [ 'desktop' => [ 'value' => [ 'color' => self::COLORS['blush/30'] ] ] ],
                'padding'     => [
                    'desktop' => [
                        'value' => [
                            'top'    => '80px',
                            'right'  => '32px',
                            'bottom' => '80px',
                            'left'   => '32px',
                        ],
                    ],
                ],
            ] ),
            $newsletter_row
        );

        // ── 6. FOOTER ─────────────────────────────────────────────────
        $footer_col = $this->block(
            'column',
            '{"type":{"desktop":{"value":"4_4"}}}',
            // Top: 5-col layout
            $this->block( 'row', '{"modulePreset":"default"}',
                // Col 1: Logo + text + socials
                $this->block( 'column', '{"type":{"desktop":{"value":"1_5"}}}',
                    $this->block( 'text', '{}',
                        $this->inner_content( '<div style="color:#fff;"><span style="font-size:16px;">◆</span><span style="font-family:Playfair Display;font-size:14px;letter-spacing:2px;margin-left:8px;">LAIA PANAMA</span></div>'
                            . '<p style="font-family:Inter;font-size:13px;color:#999;margin-top:12px;max-width:200px;">Redefiniendo el estándar de la alta joyería contemporánea con una visión cálida y sofisticada.</p>'
                            . '<div style="display:flex;gap:12px;margin-top:16px;">'
                            . '<a href="#" style="color:#fff;font-size:18px;">📷</a>'
                            . '<a href="#" style="color:#fff;font-size:18px;">📘</a>'
                            . '<a href="#" style="color:#fff;font-size:18px;">🐦</a>'
                            . '</div>' )
                    )
                ) . "\n" .
                // Col 2: Menú Editorial
                $this->block( 'column', '{"type":{"desktop":{"value":"1_5"}}}',
                    $this->block( 'text', '{}',
                        $this->inner_content( '<h4 style="font-family:Manrope;font-size:11px;color:#fff;letter-spacing:2px;text-transform:uppercase;margin-bottom:16px;">Menú Editorial</h4>'
                            . '<ul style="list-style:none;padding:0;margin:0;">'
                            . '<li><a href="/nuestra-historia" style="font-family:Inter;font-size:13px;color:#999;text-decoration:none;display:block;margin-bottom:8px;">Nuestra Filosofía</a></li>'
                            . '<li><a href="/colecciones" style="font-family:Inter;font-size:13px;color:#999;text-decoration:none;display:block;margin-bottom:8px;">Colecciones</a></li>'
                            . '<li><a href="/contacto" style="font-family:Inter;font-size:13px;color:#999;text-decoration:none;display:block;margin-bottom:8px;">LAIA PANAMA</a></li>'
                            . '<li><a href="/blog" style="font-family:Inter;font-size:13px;color:#999;text-decoration:none;display:block;">Blog</a></li>'
                            . '</ul>' )
                    )
                ) . "\n" .
                // Col 3: Atención Exclusiva
                $this->block( 'column', '{"type":{"desktop":{"value":"1_5"}}}',
                    $this->block( 'text', '{}',
                        $this->inner_content( '<h4 style="font-family:Manrope;font-size:11px;color:#fff;letter-spacing:2px;text-transform:uppercase;margin-bottom:16px;">Atención Exclusiva</h4>'
                            . '<ul style="list-style:none;padding:0;margin:0;">'
                            . '<li style="font-family:Inter;font-size:13px;color:#999;margin-bottom:8px;">+507 8888-0000</li>'
                            . '<li style="font-family:Inter;font-size:13px;color:#999;margin-bottom:8px;">info@laiapanama.com</li>'
                            . '<li style="font-family:Inter;font-size:13px;color:#999;">Flagship Boutique, Panamá</li>'
                            . '</ul>' )
                    )
                ) . "\n" .
                // Col 4: Servicio al Cliente
                $this->block( 'column', '{"type":{"desktop":{"value":"1_5"}}}',
                    $this->block( 'text', '{}',
                        $this->inner_content( '<h4 style="font-family:Manrope;font-size:11px;color:#fff;letter-spacing:2px;text-transform:uppercase;margin-bottom:16px;">Servicio al Cliente</h4>'
                            . '<ul style="list-style:none;padding:0;margin:0;">'
                            . '<li><a href="/contacto" style="font-family:Inter;font-size:13px;color:#999;text-decoration:none;display:block;">Contacto</a></li>'
                            . '</ul>' )
                    )
                ) . "\n" .
                // Col 5: Suscripción Privada
                $this->block( 'column', '{"type":{"desktop":{"value":"1_5"}}}',
                    $this->block( 'text', '{}',
                        $this->inner_content( '<h4 style="font-family:Manrope;font-size:11px;color:#fff;letter-spacing:2px;text-transform:uppercase;margin-bottom:16px;">Suscripción Privada</h4>'
                            . '<p style="font-family:Inter;font-size:13px;color:#999;">Reciba novedades sobre colecciones cápsula y eventos privados.</p>' )
                    )
                )
            ) . "\n" .
            // Payment icons + copyright
            $this->block( 'text', '{}',
                $this->inner_content( '<div style="border-top:1px solid #333;margin-top:40px;padding-top:24px;display:flex;justify-content:space-between;align-items:center;">'
                    . '<div style="display:flex;gap:8px;align-items:center;">'
                    . '<span style="font-family:Inter;font-size:11px;color:#fff;border:1px solid #555;padding:4px 8px;">VISA</span>'
                    . '<span style="font-family:Inter;font-size:11px;color:#fff;border:1px solid #555;padding:4px 8px;">MC</span>'
                    . '<span style="font-family:Inter;font-size:11px;color:#fff;border:1px solid #555;padding:4px 8px;">AMEX</span>'
                    . '</div>'
                    . '<p style="font-family:Inter;font-size:12px;color:#666;">© 2026 LAIA PANAMA. HIGH JEWELRY & DESIGN.</p>'
                    . '<div style="display:flex;gap:16px;">'
                    . '<a href="/terminos" style="font-family:Inter;font-size:12px;color:#666;text-decoration:none;">Términos</a>'
                    . '<a href="/privacidad" style="font-family:Inter;font-size:12px;color:#666;text-decoration:none;">Privacidad</a>'
                    . '<a href="/cookies" style="font-family:Inter;font-size:12px;color:#666;text-decoration:none;">Cookies</a>'
                    . '</div>'
                    . '</div>' )
            )
        );

        $footer_row = $this->block( 'row', '{"modulePreset":"default"}', $footer_col );
        $blocks[] = $this->block(
            'section',
            json_encode( [
                'modulePreset' => 'default',
                'background'  => [ 'desktop' => [ 'value' => [ 'color' => self::COLORS['noir'] ] ] ],
                'padding'     => [
                    'desktop' => [
                        'value' => [
                            'top'    => '64px',
                            'right'  => '32px',
                            'bottom' => '32px',
                            'left'   => '32px',
                        ],
                    ],
                ],
            ] ),
            $footer_row
        );

        return implode( "\n\n", $blocks );
    }

    /**
     * Render a preview of generated blocks for debug inspection
     *
     * @param array $blocks Array of block strings
     * @return array{ module_type: string, rendered_html: string }
     */
    public function render_preview( array $blocks ): array {
        $preview = [];

        foreach ( $blocks as $block ) {
            // Extract module type from block comment
            if ( preg_match( '/<!-- wp:divi\/([^\s]+)/', $block, $m ) ) {
                $module_type = $m[1];

                // Strip block comments to get inner HTML
                $html = preg_replace( '/<!-- wp:divi\/[^\s]+\s+(\{[^}]*\})?\s*(-->)?/', '', $block );
                $html = preg_replace( '/<!-- \/wp:divi\/[^\s]+\s*-->/', '', $html );
                $html = trim( $html );

                $preview[] = [
                    'module_type'   => $module_type,
                    'rendered_html' => $html,
                ];
            }
        }

        return $preview;
    }

}

/**
 * Legacy Divi 4 Generator — kept for backward compatibility
 * Used when content contains et_pb_ shortcodes
 */
class WP_MCP_Divi_Generator {

    public function generate_page( array $layout_spec, string $style = 'modern' ): string {
        $shortcodes = [];

        foreach ( $layout_spec['sections'] as $section ) {
            $shortcodes[] = $this->generate_section( $section, $style );
        }

        return implode( "\n", $shortcodes );
    }

    public function generate_section( array $spec, string $style ): string {
        $type = $spec['type'] ?? 'text';

        switch ( $type ) {
            case 'hero':
                return $this->generate_hero( $spec, $style );
            case 'services':
                return $this->generate_services( $spec, $style );
            case 'testimonials':
                return $this->generate_testimonials( $spec, $style );
            case 'contact_form':
                return $this->generate_contact_form( $spec, $style );
            case 'pricing':
                return $this->generate_pricing( $spec, $style );
            case 'faq':
                return $this->generate_faq( $spec, $style );
            case 'cta':
                return $this->generate_cta( $spec, $style );
            case 'about':
                return $this->generate_about( $spec, $style );
            default:
                return $this->generate_text_section( $spec, $style );
        }
    }

    private function generate_hero( array $spec, string $style ): string {
        $impact = $spec['impact_level'] ?? 'normal';
        $title_level = $impact === 'high' ? 'h1' : 'h2';

        return <<<SHORTCODE
[et_pb_section fb_anchor="Header" make_fullwidth="off" allow_player_pause="off" parallax="off" parallax_method="on" make_background_cover="off" use_pattern_color_overlay="off" use_text_color_light="off" disabled="off" disabled_on="off" background_blend="color_add" background_color="#007bff" padding_mobile="on" padding_top_bottom_1="96px" padding_left_right_1="32px" padding_top_bottom_2="96px" padding_left_right_2="32px" padding_top_bottom_3="96px" padding_left_right_3="32px" padding_always_show_cover="off" background_layout="light" custom_padding="|||" custom_padding_last_parsed="|||"]
[et_pb_row make_equal="on" disabled="off" disabled_on="off" custom_padding="|||" custom_padding_last_parsed="|||" padding_mobile="on"]
[et_pb_column type="4/4" disabled="off" disabled_on="off" background_layout="light" padding_mobile="on" custom_padding="|||" custom_padding_last_parsed="|||"]
[et_pb_text text_orientation="center" disabled="off" disabled_on="off" text_font_size="14" text_text_color="#ffffff" text_letter_spacing="0px" text_line_height="1em" use_border_color="off" border_color="#ffffff" border_style="solid" custom_margin="|||" custom_margin_last_parsed="|||" custom_padding="|||" custom_padding_last_parsed="|||"]
<h1>Bienvenido a nuestra plataforma</h1>
<p>Soluciones innovadoras para impulsar tu negocio al siguiente nivel.</p>
[/et_pb_text]
[et_pb_button button_url="#" button_text="Contáctanos" button_alignment="center" disabled="off" disabled_on="off" button_use_icon="off" button_on_hover="on" custom_padding="|||" custom_padding_last_parsed="|||" custom_margin="|||" custom_margin_last_parsed="|||" button_letter_spacing_hover="0" button_text_size="16" button_text_size_tablet="16" button_text_size_phone="16"]
[/et_pb_column]
[/et_pb_row]
[/et_pb_section]
SHORTCODE;
    }

    private function generate_services( array $spec, string $style ): string {
        $count = $spec['count'] ?? 3;
        $col_type = $count === 3 ? '1/3' : ( $count === 2 ? '1/2' : '1/4' );

        $cols = [];
        for ( $i = 1; $i <= $count; $i++ ) {
            $cols[] = "[et_pb_column type=\"{$col_type}\" disabled=\"off\" disabled_on=\"off\" background_layout=\"light\" padding_mobile=\"on\" custom_padding=\"|||\" custom_padding_last_parsed=\"|||\"]" .
                "[et_pb_blurb title=\"Servicio {$i}\" url=\"#\" image=\"https://via.placeholder.com/100x100\" content=\"Descripción del servicio {$i}.\" icon_placement=\"top\" use_circle=\"off\" circle_color=\"on\" use_circle_border=\"off\" icon_panel=\"off\" image_max_width=\"33%\" header_level=\"h4\" header_font_size=\"18\" body_font_size=\"14\" text_orientation=\"center\" custom_padding=\"|||\" custom_padding_last_parsed=\"|||\" custom_margin=\"|||\" custom_margin_last_parsed=\"|||\" custom_css_main_element=\"padding-top: 2em\"]" .
                "[/et_pb_blurb]" .
                "[/et_pb_column]";
        }

        return "[et_pb_section fb_anchor=\"Services\" make_fullwidth=\"off\" allow_player_pause=\"off\" parallax=\"off\" parallax_method=\"on\" make_background_cover=\"off\" use_pattern_color_overlay=\"off\" use_text_color_light=\"off\" disabled=\"off\" disabled_on=\"off\" background_blend=\"color_add\" background_color=\"#ffffff\" padding_mobile=\"on\" padding_top_bottom_1=\"64px\" padding_left_right_1=\"32px\" padding_always_show_cover=\"off\" background_layout=\"light\" custom_padding=\"|||\" custom_padding_last_parsed=\"|||\"]" .
            "[et_pb_row make_equal=\"on\" disabled=\"off\" disabled_on=\"off\" custom_padding=\"|||\" custom_padding_last_parsed=\"|||\" padding_mobile=\"on\"]" .
            implode( "\n", $cols ) .
            "[/et_pb_row]" .
            "[/et_pb_section]";
    }

    private function generate_testimonials( array $spec, string $style ): string {
        return <<<SHORTCODE
[et_pb_section fb_anchor="Testimonial" make_fullwidth="off" allow_player_pause="off" parallax="off" parallax_method="on" make_background_cover="off" use_pattern_color_overlay="off" use_text_color_light="off" disabled="off" disabled_on="off" background_blend="color_add" background_color="#f8f9fa" padding_mobile="on" padding_top_bottom_1="64px" padding_left_right_1="32px" padding_always_show_cover="off" background_layout="light" custom_padding="|||" custom_padding_last_parsed="|||"]
[et_pb_row make_equal="on" disabled="off" disabled_on="off" custom_padding="|||" custom_padding_last_parsed="|||" padding_mobile="on"]
[et_pb_column type="1/2" disabled="off" disabled_on="off" background_layout="light" padding_mobile="on" custom_padding="|||" custom_padding_last_parsed="|||"]
[et_pb_testimonial job_title="Cliente satisfecho" company_name="Empresa XYZ" url="#" portrait_url="https://via.placeholder.com/100x100" quote_icon="off" use_circle_color="on" custom_padding="|||" custom_padding_last_parsed="|||" custom_margin="|||" custom_margin_last_parsed="|||" custom_css_testimonial="padding-bottom: 2em"]
"Excelente servicio y atención al cliente. Muy recomendable."
[/et_pb_testimonial]
[/et_pb_column]
[et_pb_column type="1/2" disabled="off" disabled_on="off" background_layout="light" padding_mobile="on" custom_padding="|||" custom_padding_last_parsed="|||"]
[et_pb_testimonial job_title="Cliente satisfecho" company_name="Empresa ABC" url="#" portrait_url="https://via.placeholder.com/100x100" quote_icon="off" use_circle_color="on" custom_padding="|||" custom_padding_last_parsed="|||" custom_margin="|||" custom_margin_last_parsed="|||" custom_css_testimonial="padding-bottom: 2em"]
"Los resultados superaron mis expectativas."
[/et_pb_testimonial]
[/et_pb_column]
[/et_pb_row]
[/et_pb_section]
SHORTCODE;
    }

    private function generate_contact_form( array $spec, string $style ): string {
        return <<<SHORTCODE
[et_pb_section fb_anchor="Contact" make_fullwidth="off" allow_player_pause="off" parallax="off" parallax_method="on" make_background_cover="off" use_pattern_color_overlay="off" use_text_color_light="off" disabled="off" disabled_on="off" background_blend="color_add" background_color="#ffffff" padding_mobile="on" padding_top_bottom_1="64px" padding_left_right_1="32px" padding_always_show_cover="off" background_layout="light" custom_padding="|||" custom_padding_last_parsed="|||"]
[et_pb_row make_equal="on" disabled="off" disabled_on="off" custom_padding="|||" custom_padding_last_parsed="|||" padding_mobile="on"]
[et_pb_column type="2/3" disabled="off" disabled_on="off" background_layout="light" padding_mobile="on" custom_padding="|||" custom_padding_last_parsed="|||"]
[et_pb_contact_form submit_text="Enviar mensaje" submit_button_text="Enviar" email="admin@example.com" title="Contáctanos" custom_message="Hola, me gustaría obtener más información." honeypot="off" form_field_background_color="rgba(0,0,0,0)" form_field_text_color="#000000" focus_background_color="rgba(0,0,0,0)" focus_text_color="#000000" custom_padding="|||" custom_padding_last_parsed="|||" custom_margin="|||" custom_margin_last_parsed="|||" custom_css="padding-top: 2em"]
[/et_pb_contact_form]
[/et_pb_column]
[et_pb_column type="1/3" disabled="off" disabled_on="off" background_layout="light" padding_mobile="on" custom_padding="|||" custom_padding_last_parsed="|||"]
[et_pb_text text_orientation="left" disabled="off" disabled_on="off" text_font_size="14" text_text_color="#333333" custom_padding="|||" custom_padding_last_parsed="|||" custom_margin="|||" custom_margin_last_parsed="|||"]
**Información de contacto**
Dirección: Calle Ejemplo 123
Teléfono: +1 234 567 890
Email: info@example.com
[/et_pb_text]
[/et_pb_column]
[/et_pb_row]
[/et_pb_section]
SHORTCODE;
    }

    private function generate_pricing( array $spec, string $style ): string {
        $count = $spec['count'] ?? 3;
        $items = [];

        for ( $i = 1; $i <= $count; $i++ ) {
            $items[] = "[et_pb_pricing_table title=\"Plan {$i}\" currency=\"$\" sum_price=\"{$i}9\" frequency=\"/mes\" button_url=\"#\" button_text=\"Elegir plan {$i}\" offers_html=\"<li class='et_pb_pricing_feature'>Característica {$i}a</li><li class='et_pb_pricing_feature'>Característica {$i}b</li><li class='et_pb_pricing_feature'>Soporte prioritario</li>\" featured_background_color=\"#007bff\" custom_padding=\"|||\" custom_padding_last_parsed=\"|||\" custom_margin=\"|||\" custom_margin_last_parsed=\"|||\" custom_css_main_element=\"padding-top: 2em\"]";
        }

        return "[et_pb_section fb_anchor=\"Pricing\" make_fullwidth=\"off\" allow_player_pause=\"off\" parallax=\"off\" parallax_method=\"on\" make_background_cover=\"off\" use_pattern_color_overlay=\"off\" use_text_color_light=\"off\" disabled=\"off\" disabled_on=\"off\" background_blend=\"color_add\" background_color=\"#ffffff\" padding_mobile=\"on\" padding_top_bottom_1=\"64px\" padding_left_right_1=\"32px\" padding_always_show_cover=\"off\" background_layout=\"light\" custom_padding=\"|||\" custom_padding_last_parsed=\"|||\"]" .
            "[et_pb_row make_equal=\"on\" disabled=\"off\" disabled_on=\"off\" custom_padding=\"|||\" custom_padding_last_parsed=\"|||\" padding_mobile=\"on\"]" .
            implode( "\n", $items ) .
            "[/et_pb_row]" .
            "[/et_pb_section]";
    }

    private function generate_faq( array $spec, string $style ): string {
        return <<<SHORTCODE
[et_pb_section fb_anchor="FAQ" make_fullwidth="off" allow_player_pause="off" parallax="off" parallax_method="on" make_background_cover="off" use_pattern_color_overlay="off" use_text_color_light="off" disabled="off" disabled_on="off" background_blend="color_add" background_color="#f8f9fa" padding_mobile="on" padding_top_bottom_1="64px" padding_left_right_1="32px" padding_always_show_cover="off" background_layout="light" custom_padding="|||" custom_padding_last_parsed="|||"]
[et_pb_row make_equal="on" disabled="off" disabled_on="off" custom_padding="|||" custom_padding_last_parsed="|||" padding_mobile="on"]
[et_pb_column type="4/4" disabled="off" disabled_on="off" background_layout="light" padding_mobile="on" custom_padding="|||" custom_padding_last_parsed="|||"]
[et_pb_accordion]
[et_pb_accordion_item title="¿Cuáles son los tiempos de entrega?" open="on" custom_padding="|||" custom_padding_last_parsed="|||" custom_margin="|||" custom_margin_last_parsed="|||"]
Nuestros tiempos de entrega varían según la complejidad del proyecto. Generalmente entre 5-15 días hábiles.
[/et_pb_accordion_item]
[et_pb_accordion_item title="¿Ofrecen soporte postventa?" custom_padding="|||" custom_padding_last_parsed="|||" custom_margin="|||" custom_margin_last_parsed="|||"]
Sí, todos nuestros servicios incluyen 30 días de soporte gratuito post-implementación.
[/et_pb_accordion_item]
[et_pb_accordion_item title="¿Cómo funcionan los pagos?" custom_padding="|||" custom_padding_last_parsed="|||" custom_margin="|||" custom_margin_last_parsed="|||"]
Aceptamos transferencia bancaria, tarjeta de crédito y PayPal. 50% inicial, 50% a la entrega.
[/et_pb_accordion_item]
[/et_pb_accordion]
[/et_pb_column]
[/et_pb_row]
[/et_pb_section]
SHORTCODE;
    }

    private function generate_cta( array $spec, string $style ): string {
        return <<<SHORTCODE
[et_pb_section fb_anchor="CTA" make_fullwidth="on" allow_player_pause="off" parallax="off" parallax_method="on" make_background_cover="off" use_pattern_color_overlay="off" use_text_color_light="off" disabled="off" disabled_on="off" background_blend="color_add" background_color="#007bff" padding_mobile="on" padding_top_bottom_1="80px" padding_left_right_1="32px" padding_always_show_cover="off" background_layout="dark" custom_padding="|||" custom_padding_last_parsed="|||"]
[et_pb_row make_equal="on" disabled="off" disabled_on="off" custom_padding="|||" custom_padding_last_parsed="|||" padding_mobile="on"]
[et_pb_column type="4/4" disabled="off" disabled_on="off" background_layout="light" padding_mobile="on" custom_padding="|||" custom_padding_last_parsed="|||"]
[et_pb_text text_orientation="center" disabled="off" disabled_on="off" text_font_size="28" text_text_color="#ffffff" text_letter_spacing="0px" text_line_height="1.4em" use_border_color="off" border_color="#ffffff" border_style="solid" custom_padding="|||" custom_padding_last_parsed="|||"]
¿Listo para comenzar? ¡Contáctanos hoy mismo!
[/et_pb_text]
[et_pb_button button_url="#" button_text="Contactar ahora" button_alignment="center" disabled="off" disabled_on="off" button_use_icon="off" button_on_hover="on" custom_padding="|||" custom_padding_last_parsed="|||" custom_margin="|||" custom_margin_last_parsed="|||" button_letter_spacing_hover="0" button_text_size="16" button_text_size_tablet="16" button_text_size_phone="16"]
[/et_pb_column]
[/et_pb_row]
[/et_pb_section]
SHORTCODE;
    }

    private function generate_about( array $spec, string $style ): string {
        return <<<SHORTCODE
[et_pb_section fb_anchor="About" make_fullwidth="off" allow_player_pause="off" parallax="off" parallax_method="on" make_background_cover="off" use_pattern_color_overlay="off" use_text_color_light="off" disabled="off" disabled_on="off" background_blend="color_add" background_color="#ffffff" padding_mobile="on" padding_top_bottom_1="64px" padding_left_right_1="32px" padding_always_show_cover="off" background_layout="light" custom_padding="|||" custom_padding_last_parsed="|||"]
[et_pb_row make_equal="on" disabled="off" disabled_on="off" custom_padding="|||" custom_padding_last_parsed="|||" padding_mobile="on"]
[et_pb_column type="1/2" disabled="off" disabled_on="off" background_layout="light" padding_mobile="on" custom_padding="|||" custom_padding_last_parsed="|||"]
[et_pb_team_member member_name="Nombre del Equipo" member_position="Cargo / Posición" image_url="https://via.placeholder.com/300x300" icon_alignment="left" facebook_url="#" twitter_url="#" google_plus_url="#" linkedin_url="#" custom_padding="|||" custom_padding_last_parsed="|||" custom_margin="|||" custom_margin_last_parsed="|||"]
Breve descripción del miembro del equipo.
[/et_pb_team_member]
[/et_pb_column]
[et_pb_column type="1/2" disabled="off" disabled_on="off" background_layout="light" padding_mobile="on" custom_padding="|||" custom_padding_last_parsed="|||"]
[et_pb_text text_orientation="left" disabled="off" disabled_on="off" text_font_size="16" text_text_color="#333333" custom_padding="|||" custom_padding_last_parsed="|||"]
**Nuestra Misión**
Proveer soluciones innovadoras que impulsen el crecimiento de nuestros clientes.
**Nuestra Visión**
Ser la empresa líder en nuestro sector.
[/et_pb_text]
[/et_pb_column]
[/et_pb_row]
[/et_pb_section]
SHORTCODE;
    }

    private function generate_text_section( array $spec, string $style ): string {
        return <<<SHORTCODE
[et_pb_section fb_anchor="Text" make_fullwidth="off" allow_player_pause="off" parallax="off" parallax_method="on" make_background_cover="off" use_pattern_color_overlay="off" use_text_color_light="off" disabled="off" disabled_on="off" background_blend="color_add" background_color="#ffffff" padding_mobile="on" padding_top_bottom_1="48px" padding_left_right_1="32px" padding_always_show_cover="off" background_layout="light" custom_padding="|||" custom_padding_last_parsed="|||"]
[et_pb_row make_equal="on" disabled="off" disabled_on="off" custom_padding="|||" custom_padding_last_parsed="|||" padding_mobile="on"]
[et_pb_column type="4/4" disabled="off" disabled_on="off" background_layout="light" padding_mobile="on" custom_padding="|||" custom_padding_last_parsed="|||"]
[et_pb_text text_orientation="left" disabled="off" disabled_on="off" text_font_size="16" text_text_color="#333333" custom_padding="|||" custom_padding_last_parsed="|||"]
Contenido de la página
[/et_pb_text]
[/et_pb_column]
[/et_pb_row]
[/et_pb_section]
SHORTCODE;
    }
}

/**
 * Design System — manages color/font/spacing presets
 */
class WP_MCP_Design_System {

    private const PRESETS = [
        'modern' => [
            'primary_color'      => '#007bff',
            'secondary_color'   => '#6c757d',
            'text_color'        => '#333333',
            'background_color'  => '#ffffff',
            'font_family'       => 'Inter, sans-serif',
            'heading_font'      => 'Inter, sans-serif',
            'button_bg'         => '#007bff',
            'button_text'       => '#ffffff',
        ],
        'corporate' => [
            'primary_color'      => '#1a1a2e',
            'secondary_color'   => '#16213e',
            'text_color'        => '#333333',
            'background_color'  => '#f8f9fa',
            'font_family'       => 'Roboto, sans-serif',
            'heading_font'     => 'Roboto, sans-serif',
            'button_bg'         => '#1a1a2e',
            'button_text'       => '#ffffff',
        ],
        'minimal' => [
            'primary_color'      => '#333333',
            'secondary_color'   => '#666666',
            'text_color'        => '#333333',
            'background_color'  => '#ffffff',
            'font_family'       => 'Open Sans, sans-serif',
            'heading_font'      => 'Open Sans, sans-serif',
            'button_bg'         => '#333333',
            'button_text'       => '#ffffff',
        ],
        'bold' => [
            'primary_color'      => '#e63946',
            'secondary_color'   => '#457b9d',
            'text_color'        => '#333333',
            'background_color'  => '#ffffff',
            'font_family'       => 'Montserrat, sans-serif',
            'heading_font'     => 'Montserrat, sans-serif',
            'button_bg'         => '#e63946',
            'button_text'       => '#ffffff',
        ],
        'elegant' => [
            'primary_color'      => '#2c3e50',
            'secondary_color'   => '#e8e8e8',
            'text_color'        => '#333333',
            'background_color'  => '#fafafa',
            'font_family'       => 'Georgia, serif',
            'heading_font'      => 'Playfair Display, serif',
            'button_bg'         => '#2c3e50',
            'button_text'       => '#ffffff',
        ],
    ];

    public function get_preset( string $name ): array {
        return self::PRESETS[ $name ] ?? self::PRESETS['modern'];
    }

    public function apply_preset( array $context, string $preset ): array {
        $preset_values = $this->get_preset( $preset );
        return array_merge( $context, $preset_values );
    }
}

/**
 * CRO Engine — scores UX/SEO/performance and applies optimizations
 * Supports both Divi 4 (shortcode) and Divi 5 (block) formats
 */
class WP_MCP_CRO_Engine {

    public function calculate_scores( string $content, string $format = 'd4_shortcode' ): array {
        if ( $format === 'd5_block' ) {
            return [
                'ux'          => $this->calculate_ux_score_d5( $content ),
                'seo'         => $this->calculate_seo_score_d5( $content ),
                'performance' => $this->calculate_performance_score_d5( $content ),
            ];
        }
        return [
            'ux'          => $this->calculate_ux_score_d4( $content ),
            'seo'         => $this->calculate_seo_score_d4( $content ),
            'performance' => $this->calculate_performance_score_d4( $content ),
        ];
    }

    // ─── Divi 5 Block Format Scores ───────────────────────────────

    private function calculate_ux_score_d5( string $content ): int {
        $score = 50;

        $cta_count = substr_count( $content, 'wp:divi/button' ) + substr_count( $content, 'wp:divi/cta' );
        if ( $cta_count >= 2 ) {
            $score += 15;
        } elseif ( $cta_count === 1 ) {
            $score += 8;
        }

        if ( strpos( $content, 'wp:divi/testimonial' ) !== false ) {
            $score += 10;
        }

        if ( strpos( $content, 'wp:divi/contact_form' ) !== false ) {
            $score += 15;
        }

        if ( preg_match( '/"padding":.*?"top".*?"96px"/', $content ) ||
             preg_match( '/"padding":.*?"top".*?"64px"/', $content ) ) {
            $score += 10;
        }

        return min( 100, $score );
    }

    private function calculate_seo_score_d5( string $content ): int {
        $score = 50;

        $h1_count = substr_count( $content, '"headingLevel":"h1"' );
        if ( $h1_count === 1 ) {
            $score += 20;
        } elseif ( $h1_count > 1 ) {
            $score -= 10;
        }

        $img_without_alt = preg_match_all( '/wp:divi\/image(?!.*"alt")/', $content );
        if ( $img_without_alt === 0 || $img_without_alt === false ) {
            $score += 15;
        } else {
            $score -= ( $img_without_alt * 5 );
        }

        $text_blocks = substr_count( $content, 'wp:divi/text' );
        if ( $text_blocks >= 3 ) {
            $score += 10;
        } elseif ( $text_blocks >= 1 ) {
            $score += 5;
        }

        return max( 0, min( 100, $score ) );
    }

    private function calculate_performance_score_d5( string $content ): int {
        $score = 70;

        if ( strpos( $content, '"loading"' ) !== false || strpos( $content, '"src"' ) !== false ) {
            $score += 15;
        }

        if ( strpos( $content, 'unsplash.com' ) !== false ) {
            $external_images = substr_count( $content, 'unsplash.com' );
            if ( $external_images > 3 ) {
                $score -= ( $external_images - 3 ) * 3;
            }
        }

        if ( preg_match( '/fonts\.googleapis\.com/', $content ) ) {
            $score -= 5;
        }

        return max( 0, min( 100, $score ) );
    }

    // ─── Divi 4 Shortcode Format Scores ──────────────────────────

    private function calculate_ux_score_d4( string $content ): int {
        $score = 50;

        $cta_count = substr_count( $content, 'et_pb_button' ) + substr_count( $content, 'et_pb_cta' );
        if ( $cta_count >= 2 ) {
            $score += 15;
        } elseif ( $cta_count === 1 ) {
            $score += 8;
        }

        if ( strpos( $content, 'et_pb_testimonial' ) !== false ) {
            $score += 10;
        }

        if ( strpos( $content, 'et_pb_contact_form' ) !== false ) {
            $score += 15;
        }

        if ( strpos( $content, 'padding_top_bottom_1="64px"' ) !== false ||
             strpos( $content, 'padding_top_bottom_1="96px"' ) !== false ) {
            $score += 10;
        }

        return min( 100, $score );
    }

    private function calculate_seo_score_d4( string $content ): int {
        $score = 50;

        $h1_count = substr_count( $content, 'header_level="h1"' );
        if ( $h1_count === 1 ) {
            $score += 20;
        } elseif ( $h1_count > 1 ) {
            $score -= 10;
        }

        $img_without_alt = preg_match_all( '/\[et_pb_image(?!.*alt=)/', $content );
        if ( $img_without_alt === 0 || $img_without_alt === false ) {
            $score += 15;
        } else {
            $score -= ( $img_without_alt * 5 );
        }

        $text_blocks = substr_count( $content, 'et_pb_text' );
        if ( $text_blocks >= 3 ) {
            $score += 10;
        } elseif ( $text_blocks >= 1 ) {
            $score += 5;
        }

        return max( 0, min( 100, $score ) );
    }

    private function calculate_performance_score_d4( string $content ): int {
        $score = 70;

        if ( strpos( $content, 'loading=' ) !== false ) {
            $score += 15;
        } else {
            $score -= 10;
        }

        $external_images = preg_match_all( '/https:\/\/via\.placeholder\.com/', $content );
        if ( $external_images !== false && $external_images > 3 ) {
            $score -= ( $external_images - 3 ) * 3;
        }

        if ( preg_match( '/fonts\.googleapis\.com/', $content ) ) {
            $score -= 5;
        }

        return max( 0, min( 100, $score ) );
    }

    // ─── Optimizations ────────────────────────────────────────────

    public function apply_optimizations( int $post_id, array $targets, string $format = 'd4_shortcode' ): array {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return [ 'success' => false, 'error' => "Post ID {$post_id} not found" ];
        }

        $content       = $post->post_content;
        $scores_before = $this->calculate_scores( $content, $format );
        $changes       = [];

        if ( $format === 'd5_block' ) {
            if ( in_array( 'speed', $targets, true ) ) {
                if ( strpos( $content, '"loading"' ) === false && strpos( $content, '"src"' ) !== false ) {
                    $content = preg_replace( '/("image":\s*\{[^}]*)("src")/', '"loading":"lazy",$1$2', $content );
                    $changes[] = 'lazy_load_added';
                }
                $changes[] = 'css_minify_indicator';
            }
            if ( in_array( 'seo', $targets, true ) ) {
                if ( preg_match( '/wp:divi\/image(?!.*"alt":\s*")/', $content ) ) {
                    $content = preg_replace(
                        '/(wp:divi\/image\s*\{[^}]*)(\})/',
                        '$1,"alt":"Imagen descriptiva"$2',
                        $content
                    );
                    $changes[] = 'missing_alts_filled';
                }
            }
            if ( in_array( 'accessibility', $targets, true ) ) {
                if ( substr_count( $content, '"headingLevel":"h1"' ) > 1 ) {
                    $content = preg_replace( '/"headingLevel":"h1"/', '"headingLevel":"h2"', $content, 1 );
                    $changes[] = 'heading_hierarchy_fixed';
                }
            }
        } else {
            // Divi 4 shortcode optimizations
            if ( in_array( 'speed', $targets, true ) ) {
                if ( strpos( $content, 'loading=' ) === false ) {
                    $content = preg_replace( '/\[et_pb_image(?!.*loading=)/', '[et_pb_image loading="lazy"', $content );
                    $changes[] = 'lazy_load_added';
                }
                $changes[] = 'css_minify_indicator';
            }
            if ( in_array( 'seo', $targets, true ) ) {
                if ( preg_match( '/\[et_pb_image(?!.*alt=)(.*?)\]/', $content ) ) {
                    $content = preg_replace(
                        '/\[et_pb_image(?!.*alt=)(.*?)\]/',
                        '[et_pb_image$1 alt="Imagen descriptiva"]',
                        $content
                    );
                    $changes[] = 'missing_alts_filled';
                }
            }
            if ( in_array( 'accessibility', $targets, true ) ) {
                if ( substr_count( $content, 'header_level="h1"' ) > 1 ) {
                    $content = preg_replace( '/header_level="h1"/', 'header_level="h2"', $content, 1 );
                    $changes[] = 'heading_hierarchy_fixed';
                }
            }
        }

        if ( ! empty( $changes ) ) {
            wp_update_post( [ 'ID' => $post_id, 'post_content' => $content ] );
        }

        $scores_after = $this->calculate_scores( $content, $format );

        return [
            'success'               => true,
            'optimizations_applied' => $changes,
            'scores_before'         => $scores_before,
            'scores_after'          => $scores_after,
        ];
    }
}

// ════════════════════════════════════════════════════════════════
// MAIN BRIDGE CLASS
// ════════════════════════════════════════════════════════════════

class WP_MCP_Bridge {

    private ?string $wpcli = null;

    public function __construct() {
        $this->wpcli = $this->detect_or_install_wpcli();
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    // ─────────────────────────────────────────────────────────
    //  WP-CLI DETECTION / AUTO-INSTALL
    // ─────────────────────────────────────────────────────────

    private function detect_or_install_wpcli(): ?string {
        $candidates = [
            '/usr/local/bin/wp',
            '/usr/bin/wp',
            ABSPATH . 'wp-cli.phar',
            sys_get_temp_dir() . '/wp-cli.phar',
        ];
        $which = trim( (string) @shell_exec( 'which wp 2>/dev/null' ) );
        if ( $which ) {
            $candidates[] = $which;
        }

        foreach ( $candidates as $p ) {
            if ( $p && @file_exists( $p ) && @is_executable( $p ) ) {
                return $p;
            }
        }
        return $this->try_install_wpcli();
    }

    private function try_install_wpcli(): ?string {
        if ( ! function_exists( 'shell_exec' ) ) {
            return null;
        }

        $target   = ABSPATH . 'wp-cli.phar';
        $phar_url = 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';

        @shell_exec( "curl -sL {$phar_url} -o " . escapeshellarg( $target ) . " 2>&1" );

        if ( ! @file_exists( $target ) ) {
            @shell_exec( "wget -q {$phar_url} -O " . escapeshellarg( $target ) . " 2>&1" );
        }

        if ( ! @file_exists( $target ) && ini_get( 'allow_url_fopen' ) ) {
            $phar = @file_get_contents( $phar_url );
            if ( $phar ) {
                @file_put_contents( $target, $phar );
            }
        }

        if ( @file_exists( $target ) ) {
            @chmod( $target, 0755 );
            return PHP_BINARY . ' ' . escapeshellarg( $target );
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────
    //  REST ROUTE REGISTRATION
    // ─────────────────────────────────────────────────────────

    public function register_routes(): void {
        $ns = WP_MCP_BRIDGE_NS;
        $p  = [ $this, 'check_permission' ];

        // Health
        register_rest_route( $ns, '/health', [ 'methods' => 'GET', 'callback' => [ $this, 'health_check' ], 'permission_callback' => $p ] );

        // Plugins
        register_rest_route( $ns, '/plugins',             [ 'methods' => 'GET',    'callback' => [ $this, 'list_plugins' ],      'permission_callback' => $p ] );
        register_rest_route( $ns, '/plugins/install',     [ 'methods' => 'POST',   'callback' => [ $this, 'install_plugin' ],    'permission_callback' => $p,
            'args' => [ 'slug' => ['required'=>true,'type'=>'string'], 'version' => ['required'=>false,'type'=>'string','default'=>''], 'activate' => ['required'=>false,'type'=>'boolean','default'=>false] ] ] );
        register_rest_route( $ns, '/plugins/activate',    [ 'methods' => 'POST',   'callback' => [ $this, 'activate_plugin' ],   'permission_callback' => $p,
            'args' => [ 'slug' => ['required'=>true,'type'=>'string'] ] ] );
        register_rest_route( $ns, '/plugins/deactivate',  [ 'methods' => 'POST',   'callback' => [ $this, 'deactivate_plugin' ], 'permission_callback' => $p,
            'args' => [ 'slug' => ['required'=>true,'type'=>'string'] ] ] );
        register_rest_route( $ns, '/plugins/delete',      [ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_plugin' ],     'permission_callback' => $p,
            'args' => [ 'slug' => ['required'=>true,'type'=>'string'] ] ] );
        register_rest_route( $ns, '/plugins/update',       [ 'methods' => 'POST',   'callback' => [ $this, 'update_plugin' ],     'permission_callback' => $p,
            'args' => [ 'slug' => ['required'=>true,'type'=>'string'] ] ] );
        register_rest_route( $ns, '/plugins/update-all',   [ 'methods' => 'POST',   'callback' => [ $this, 'update_all_plugins' ],'permission_callback' => $p ] );

        // Themes
        register_rest_route( $ns, '/themes',             [ 'methods' => 'GET',    'callback' => [ $this, 'list_themes' ],     'permission_callback' => $p ] );
        register_rest_route( $ns, '/themes/install',     [ 'methods' => 'POST',   'callback' => [ $this, 'install_theme' ], 'permission_callback' => $p,
            'args' => [ 'slug' => ['required'=>true,'type'=>'string'], 'version' => ['required'=>false,'type'=>'string','default'=>''], 'activate' => ['required'=>false,'type'=>'boolean','default'=>false] ] ] );
        register_rest_route( $ns, '/themes/activate',    [ 'methods' => 'POST',   'callback' => [ $this, 'activate_theme' ],'permission_callback' => $p,
            'args' => [ 'slug' => ['required'=>true,'type'=>'string'] ] ] );
        register_rest_route( $ns, '/themes/delete',       [ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_theme' ],  'permission_callback' => $p,
            'args' => [ 'slug' => ['required'=>true,'type'=>'string'] ] ] );

        // Options
        register_rest_route( $ns, '/options', [ 'methods' => 'GET',  'callback' => [ $this, 'get_options' ], 'permission_callback' => $p,
            'args' => [ 'keys' => ['required'=>false,'type'=>'string','default'=>''] ] ] );
        register_rest_route( $ns, '/options', [ 'methods' => 'POST', 'callback' => [ $this, 'set_option' ],  'permission_callback' => $p,
            'args' => [ 'key' => ['required'=>true,'type'=>'string'], 'value' => ['required'=>true,'type'=>'string'] ] ] );

        // Users
        register_rest_route( $ns, '/users', [ 'methods' => 'GET',  'callback' => [ $this, 'list_users' ], 'permission_callback' => $p ] );
        register_rest_route( $ns, '/users', [ 'methods' => 'POST', 'callback' => [ $this, 'create_user' ], 'permission_callback' => $p,
            'args' => [
                'username' => ['required'=>true, 'type'=>'string'],
                'email'    => ['required'=>true, 'type'=>'string'],
                'password' => ['required'=>true, 'type'=>'string'],
                'role'     => ['required'=>false,'type'=>'string','default'=>'subscriber'],
            ] ] );

        // Maintenance, cache, DB
        register_rest_route( $ns, '/maintenance/enable',  [ 'methods' => 'POST', 'callback' => [ $this, 'maintenance_on' ],  'permission_callback' => $p ] );
        register_rest_route( $ns, '/maintenance/disable', [ 'methods' => 'POST', 'callback' => [ $this, 'maintenance_off' ], 'permission_callback' => $p ] );
        register_rest_route( $ns, '/cache/flush',          [ 'methods' => 'POST', 'callback' => [ $this, 'flush_cache' ],      'permission_callback' => $p ] );
        register_rest_route( $ns, '/db/optimize',           [ 'methods' => 'POST', 'callback' => [ $this, 'optimize_db' ],      'permission_callback' => $p ] );

        // CLI direct (only if WP-CLI available)
        register_rest_route( $ns, '/cli', [ 'methods' => 'POST', 'callback' => [ $this, 'run_cli' ], 'permission_callback' => $p,
            'args' => [ 'command' => ['required'=>true,'type'=>'string'] ] ] );

        // Divi Engine routes
        register_rest_route( $ns, '/divi/page', [ 'methods' => 'POST', 'callback' => [ $this, 'create_divi_page' ], 'permission_callback' => $p,
            'args' => [
                'prompt' => ['required'=>true,'type'=>'string'],
                'title'  => ['required'=>true,'type'=>'string'],
                'style'  => ['required'=>false,'type'=>'string','default'=>'modern'],
            ] ] );
        register_rest_route( $ns, '/divi/theme-settings', [ 'methods' => ['GET','POST'], 'callback' => [ $this, 'update_divi_theme_settings' ], 'permission_callback' => $p ] );

        // Theme Builder template endpoints
        register_rest_route( $ns, '/divi/header', [
            'methods'  => 'POST',
            'callback' => [ $this, 'create_divi_header_template' ],
            'permission_callback' => $p,
            'args' => [
                'title' => ['required' => false, 'type' => 'string', 'default' => 'LAIA Panama Header'],
            ],
        ] );

        register_rest_route( $ns, '/divi/footer', [
            'methods'  => 'POST',
            'callback' => [ $this, 'create_divi_footer_template' ],
            'permission_callback' => $p,
            'args' => [
                'title' => ['required' => false, 'type' => 'string', 'default' => 'LAIA Panama Footer'],
            ],
        ] );

        register_rest_route( $ns, '/divi/body', [
            'methods'  => 'POST',
            'callback' => [ $this, 'create_divi_body_page' ],
            'permission_callback' => $p,
            'args' => [
                'page'  => ['required' => true,  'type' => 'string'],
                'title' => ['required' => false, 'type' => 'string', 'default' => ''],
                'style' => ['required' => false, 'type' => 'string', 'default' => 'modern'],
            ],
        ] );

        register_rest_route( $ns, '/divi/body-raw', [
            'methods'  => 'POST',
            'callback' => [ $this, 'create_divi_body_raw' ],
            'permission_callback' => $p,
            'args' => [
                'page'    => ['required' => true,  'type' => 'string'],
                'title'   => ['required' => false, 'type' => 'string', 'default' => ''],
                'style'   => ['required' => false, 'type' => 'string', 'default' => 'modern'],
                'content' => ['required' => false, 'type' => 'string', 'default' => ''],
            ],
        ] );

        register_rest_route( $ns, '/divi/template-raw', [
            'methods'  => 'POST',
            'callback' => [ $this, 'create_divi_template_raw' ],
            'permission_callback' => $p,
            'args' => [
                'type'    => ['required' => true,  'type' => 'string'],
                'title'   => ['required' => false, 'type' => 'string', 'default' => ''],
                'style'   => ['required' => false, 'type' => 'string', 'default' => 'modern'],
            ],
        ] );

        register_rest_route( $ns, '/divi/update-content', [
            'methods'  => 'POST',
            'callback' => [ $this, 'update_page_content_raw' ],
            'permission_callback' => $p,
            'args' => [
                'post_id' => ['required' => true,  'type' => 'integer'],
                'content' => ['required' => true,  'type' => 'string'],
            ],
        ] );

        register_rest_route( $ns, '/divi/page/optimize', [ 'methods' => 'POST', 'callback' => [ $this, 'optimize_divi_page' ], 'permission_callback' => $p,
            'args' => [
                'post_id'              => ['required'=>true,'type'=>'integer'],
                'optimization_targets' => ['required'=>false,'type'=>'array','default'=>['speed','seo','accessibility']],
                'preview'              => ['required'=>false,'type'=>'boolean','default'=>false],
            ] ] );
        register_rest_route( $ns, '/divi/page/analyze', [ 'methods' => 'POST', 'callback' => [ $this, 'analyze_divi_page' ], 'permission_callback' => $p,
            'args' => [
                'post_id'       => ['required'=>true,'type'=>'integer'],
                'force_refresh' => ['required'=>false,'type'=>'boolean','default'=>false],
            ] ] );
    }

    // ─────────────────────────────────────────────────────────
    //  PERMISSION CHECK
    // ─────────────────────────────────────────────────────────

    public function check_permission( WP_REST_Request $req ) {
        $secret = $req->get_header( 'X-MCP-Secret' );
        if ( WP_MCP_SECRET !== '' && $secret !== WP_MCP_SECRET ) {
            return new WP_Error( 'mcp_forbidden', 'X-MCP-Secret inválido.', [ 'status' => 403 ] );
        }
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'mcp_unauthorized', 'Requiere autenticación de administrador.', [ 'status' => 401 ] );
        }
        return true;
    }

    // ─────────────────────────────────────────────────────────
    //  HELPERS
    // ─────────────────────────────────────────────────────────

    private function cli( string $cmd ): ?array {
        if ( ! $this->wpcli ) {
            return null;
        }
        $abspath = escapeshellarg( ABSPATH );
        $full    = "{$this->wpcli} {$cmd} --path={$abspath} --allow-root 2>&1";
        $out = [];
        $code = 0;
        exec( $full, $out, $code );
        return [ 'via' => 'wpcli', 'success' => $code === 0, 'output' => implode( "\n", $out ), 'code' => $code ];
    }

    private function run( string $cli_cmd, callable $php_cb ): array {
        $r = $this->cli( $cli_cmd );
        return $r ?? $php_cb();
    }

    private function load_upgrader(): void {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        WP_Filesystem();
    }

    private function find_plugin_file( string $slug ): ?string {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach ( array_keys( get_plugins() ) as $file ) {
            if ( strpos( $file, $slug . '/' ) === 0 || $file === $slug . '.php' ) {
                return $file;
            }
        }
        return null;
    }

    private function ok( string $msg, $extra = [] ): array {
        return array_merge( [ 'via' => 'php', 'success' => true, 'output' => $msg ], $extra );
    }

    private function fail( string $msg ): array {
        return [ 'via' => 'php', 'success' => false, 'output' => $msg ];
    }

    // ─────────────────────────────────────────────────────────
    //  HEALTH CHECK
    // ─────────────────────────────────────────────────────────

    public function health_check(): WP_REST_Response {
        $cli_v = null;
        if ( $this->wpcli ) {
            $r = $this->cli( '--version' );
            $cli_v = $r['output'] ?? null;
        }
        return rest_ensure_response([
            'status'         => 'ok',
            'wp_version'     => get_bloginfo( 'version' ),
            'php_version'    => PHP_VERSION,
            'bridge_version' => WP_MCP_BRIDGE_VERSION,
            'mode'           => $this->wpcli ? 'wpcli' : 'php_fallback',
            'wpcli_version'  => $cli_v ?? 'no disponible — usando PHP puro',
        ]);
    }

    // ─────────────────────────────────────────────────────────
    //  PLUGINS
    // ─────────────────────────────────────────────────────────

    public function list_plugins(): WP_REST_Response {
        return rest_ensure_response( $this->run( 'plugin list --format=json', function() {
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $active = get_option( 'active_plugins', [] );
            $list   = [];
            foreach ( get_plugins() as $file => $d ) {
                $list[] = [
                    'slug'    => explode( '/', $file )[0],
                    'file'    => $file,
                    'name'    => $d['Name'],
                    'version' => $d['Version'],
                    'status'  => in_array( $file, $active ) ? 'active' : 'inactive',
                    'author'  => $d['Author'],
                ];
            }
            return [ 'via' => 'php', 'success' => true, 'plugins' => $list ];
        }) );
    }

    public function install_plugin( WP_REST_Request $req ): WP_REST_Response {
        $slug = sanitize_key( $req->get_param( 'slug' ) );
        $ver  = (string) $req->get_param( 'version' );
        $act  = (bool)   $req->get_param( 'activate' );
        $cmd  = "plugin install {$slug}" . ( $ver ? " --version={$ver}" : '' ) . ( $act ? ' --activate' : '' );
        return rest_ensure_response( $this->run( $cmd, function() use ( $slug, $ver, $act ) {
            $this->load_upgrader();
            $url = $ver
                ? "https://downloads.wordpress.org/plugin/{$slug}.{$ver}.zip"
                : "https://downloads.wordpress.org/plugin/{$slug}.latest-stable.zip";
            $skin = new WP_Ajax_Upgrader_Skin();
            $r    = ( new Plugin_Upgrader( $skin ) )->install( $url );
            if ( is_wp_error( $r ) ) {
                return $this->fail( $r->get_error_message() );
            }
            if ( ! $r ) {
                return $this->fail( implode( "\n", $skin->get_upgrade_messages() ) ?: "Instalación fallida." );
            }
            $msg = "Plugin '{$slug}' instalado.";
            if ( $act ) {
                $file = $this->find_plugin_file( $slug );
                if ( $file ) {
                    $a = activate_plugin( $file );
                    $msg .= is_wp_error( $a ) ? " Error al activar: " . $a->get_error_message() : " Activado.";
                }
            }
            return $this->ok( $msg );
        }) );
    }

    public function activate_plugin( WP_REST_Request $req ): WP_REST_Response {
        $slug = sanitize_key( $req->get_param( 'slug' ) );
        return rest_ensure_response( $this->run( "plugin activate {$slug}", function() use ( $slug ) {
            if ( ! function_exists( 'activate_plugin' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $file = $this->find_plugin_file( $slug );
            if ( ! $file ) {
                return $this->fail( "Plugin '{$slug}' no encontrado." );
            }
            $r = activate_plugin( $file );
            if ( is_wp_error( $r ) ) {
                return $this->fail( $r->get_error_message() );
            }
            return $this->ok( "Plugin '{$slug}' activado." );
        }) );
    }

    public function deactivate_plugin( WP_REST_Request $req ): WP_REST_Response {
        $slug = sanitize_key( $req->get_param( 'slug' ) );
        return rest_ensure_response( $this->run( "plugin deactivate {$slug}", function() use ( $slug ) {
            if ( ! function_exists( 'deactivate_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $file = $this->find_plugin_file( $slug );
            if ( ! $file ) {
                return $this->fail( "Plugin '{$slug}' no encontrado." );
            }
            deactivate_plugins( $file );
            return $this->ok( "Plugin '{$slug}' desactivado." );
        }) );
    }

    public function delete_plugin( WP_REST_Request $req ): WP_REST_Response {
        $slug = sanitize_key( $req->get_param( 'slug' ) );
        return rest_ensure_response( $this->run( "plugin delete {$slug}", function() use ( $slug ) {
            if ( ! function_exists( 'delete_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $file = $this->find_plugin_file( $slug );
            if ( ! $file ) {
                return $this->fail( "Plugin '{$slug}' no encontrado." );
            }
            deactivate_plugins( $file );
            $r = delete_plugins( [ $file ] );
            if ( is_wp_error( $r ) ) {
                return $this->fail( $r->get_error_message() );
            }
            return $this->ok( "Plugin '{$slug}' eliminado." );
        }) );
    }

    public function update_plugin( WP_REST_Request $req ): WP_REST_Response {
        $slug = sanitize_key( $req->get_param( 'slug' ) );
        return rest_ensure_response( $this->run( "plugin update {$slug}", function() use ( $slug ) {
            $this->load_upgrader();
            $file = $this->find_plugin_file( $slug );
            if ( ! $file ) {
                return $this->fail( "Plugin '{$slug}' no encontrado." );
            }
            wp_update_plugins();
            $skin = new WP_Ajax_Upgrader_Skin();
            $r    = ( new Plugin_Upgrader( $skin ) )->upgrade( $file );
            if ( is_wp_error( $r ) ) {
                return $this->fail( $r->get_error_message() );
            }
            if ( $r === false ) {
                return $this->fail( "Sin actualizaciones disponibles." );
            }
            return $this->ok( "Plugin '{$slug}' actualizado." );
        }) );
    }

    public function update_all_plugins(): WP_REST_Response {
        return rest_ensure_response( $this->run( 'plugin update --all', function() {
            $this->load_upgrader();
            wp_update_plugins();
            $updates = get_site_transient( 'update_plugins' );
            if ( empty( $updates->response ) ) {
                return $this->ok( 'Todos los plugins están al día.' );
            }
            $results = [];
            foreach ( $updates->response as $file => $d ) {
                $slug    = explode( '/', $file )[0];
                $skin    = new WP_Ajax_Upgrader_Skin();
                $r       = ( new Plugin_Upgrader( $skin ) )->upgrade( $file );
                $results[] = $slug . ': ' . ( $r ? 'actualizado' : 'error' );
            }
            return $this->ok( implode( "\n", $results ) );
        }) );
    }

    // ─────────────────────────────────────────────────────────
    //  THEMES
    // ─────────────────────────────────────────────────────────

    public function list_themes(): WP_REST_Response {
        return rest_ensure_response( $this->run( 'theme list --format=json', function() {
            $active = get_option( 'stylesheet' );
            $list   = [];
            foreach ( wp_get_themes() as $slug => $t ) {
                $list[] = [
                    'slug'    => $slug,
                    'name'    => $t->get( 'Name' ),
                    'version' => $t->get( 'Version' ),
                    'status'  => $slug === $active ? 'active' : 'inactive',
                    'author'  => $t->get( 'Author' ),
                ];
            }
            return [ 'via' => 'php', 'success' => true, 'themes' => $list ];
        }) );
    }

    public function install_theme( WP_REST_Request $req ): WP_REST_Response {
        $slug = sanitize_key( $req->get_param( 'slug' ) );
        $ver  = (string) $req->get_param( 'version' );
        $act  = (bool)   $req->get_param( 'activate' );
        $cmd  = "theme install {$slug}" . ( $ver ? " --version={$ver}" : '' ) . ( $act ? ' --activate' : '' );
        return rest_ensure_response( $this->run( $cmd, function() use ( $slug, $ver, $act ) {
            $this->load_upgrader();
            require_once ABSPATH . 'wp-admin/includes/theme.php';
            $url  = $ver
                ? "https://downloads.wordpress.org/theme/{$slug}.{$ver}.zip"
                : "https://downloads.wordpress.org/theme/{$slug}.latest-stable.zip";
            $skin = new WP_Ajax_Upgrader_Skin();
            $r    = ( new Theme_Upgrader( $skin ) )->install( $url );
            if ( is_wp_error( $r ) ) {
                return $this->fail( $r->get_error_message() );
            }
            if ( ! $r ) {
                return $this->fail( "Instalación de tema fallida." );
            }
            $msg = "Tema '{$slug}' instalado.";
            if ( $act ) {
                switch_theme( $slug );
                $msg .= " Activado.";
            }
            return $this->ok( $msg );
        }) );
    }

    public function activate_theme( WP_REST_Request $req ): WP_REST_Response {
        $slug = sanitize_key( $req->get_param( 'slug' ) );
        return rest_ensure_response( $this->run( "theme activate {$slug}", function() use ( $slug ) {
            if ( ! wp_get_theme( $slug )->exists() ) {
                return $this->fail( "Tema '{$slug}' no instalado." );
            }
            switch_theme( $slug );
            return $this->ok( "Tema '{$slug}' activado." );
        }) );
    }

    public function delete_theme( WP_REST_Request $req ): WP_REST_Response {
        $slug = sanitize_key( $req->get_param( 'slug' ) );
        return rest_ensure_response( $this->run( "theme delete {$slug}", function() use ( $slug ) {
            require_once ABSPATH . 'wp-admin/includes/theme.php';
            $r = delete_theme( $slug );
            if ( is_wp_error( $r ) ) {
                return $this->fail( $r->get_error_message() );
            }
            return $r ? $this->ok( "Tema '{$slug}' eliminado." ) : $this->fail( "No se pudo eliminar." );
        }) );
    }

    // ─────────────────────────────────────────────────────────
    //  OPTIONS
    // ─────────────────────────────────────────────────────────

    public function get_options( WP_REST_Request $req ): WP_REST_Response {
        $raw  = $req->get_param( 'keys' );
        $keys = $raw
            ? array_map( 'sanitize_key', explode( ',', $raw ) )
            : [ 'blogname','blogdescription','siteurl','home','admin_email',
                'blogpublic','default_comment_status','posts_per_page','timezone_string' ];
        $data = [];
        foreach ( $keys as $k ) {
            $data[ $k ] = get_option( $k );
        }
        return rest_ensure_response( [ 'via' => 'php', 'success' => true, 'options' => $data ] );
    }

    public function set_option( WP_REST_Request $req ): WP_REST_Response {
        $key   = sanitize_key( $req->get_param( 'key' ) );
        $value = sanitize_text_field( $req->get_param( 'value' ) );
        $protected = [ 'auth_key','secure_auth_key','logged_in_key','nonce_key','db_version' ];
        if ( in_array( $key, $protected, true ) ) {
            return rest_ensure_response( $this->fail( "Opción '{$key}' está protegida." ) );
        }
        update_option( $key, $value );
        return rest_ensure_response( $this->ok( "Opción '{$key}' → '{$value}'." ) );
    }

    // ─────────────────────────────────────────────────────────
    //  USERS
    // ─────────────────────────────────────────────────────────

    public function list_users(): WP_REST_Response {
        $list = [];
        foreach ( get_users() as $u ) {
            $list[] = [
                'id'         => $u->ID,
                'login'      => $u->user_login,
                'email'      => $u->user_email,
                'registered' => $u->user_registered,
                'roles'      => ( new WP_User( $u->ID ) )->roles,
            ];
        }
        return rest_ensure_response( [ 'via' => 'php', 'success' => true, 'users' => $list ] );
    }

    public function create_user( WP_REST_Request $req ): WP_REST_Response {
        $id = wp_create_user(
            sanitize_user( $req->get_param( 'username' ) ),
            $req->get_param( 'password' ),
            sanitize_email( $req->get_param( 'email' ) )
        );
        if ( is_wp_error( $id ) ) {
            return rest_ensure_response( $this->fail( $id->get_error_message() ) );
        }
        ( new WP_User( $id ) )->set_role( sanitize_key( $req->get_param( 'role' ) ) );
        return rest_ensure_response( $this->ok( "Usuario creado con ID {$id}.", [ 'user_id' => $id ] ) );
    }

    // ─────────────────────────────────────────────────────────
    //  MAINTENANCE / CACHE / DB
    // ─────────────────────────────────────────────────────────

    public function maintenance_on(): WP_REST_Response {
        file_put_contents( ABSPATH . '.maintenance', '<?php $upgrading = ' . time() . '; ?>' );
        return rest_ensure_response( $this->ok( 'Modo mantenimiento activado.' ) );
    }

    public function maintenance_off(): WP_REST_Response {
        $f = ABSPATH . '.maintenance';
        if ( file_exists( $f ) ) {
            unlink( $f );
        }
        return rest_ensure_response( $this->ok( 'Modo mantenimiento desactivado.' ) );
    }

    public function flush_cache(): WP_REST_Response {
        wp_cache_flush();
        if ( function_exists( 'opcache_reset' ) ) {
            opcache_reset();
        }
        return rest_ensure_response( $this->ok( 'Caché vaciado.' ) );
    }

    public function optimize_db(): WP_REST_Response {
        global $wpdb;
        $tables = $wpdb->get_col( 'SHOW TABLES' );
        foreach ( $tables as $t ) {
            $wpdb->query( "OPTIMIZE TABLE `{$t}`" );
        }
        return rest_ensure_response( $this->ok( 'DB optimizada. Tablas: ' . implode( ', ', $tables ) ) );
    }

    // ─────────────────────────────────────────────────────────
    //  CLI DIRECT
    // ─────────────────────────────────────────────────────────

    public function run_cli( WP_REST_Request $req ): WP_REST_Response {
        if ( ! $this->wpcli ) {
            return rest_ensure_response( $this->fail( 'WP-CLI no disponible. Usa los endpoints específicos.' ) );
        }

        $command = trim( $req->get_param( 'command' ) );
        $first   = strtolower( explode( ' ', $command )[0] );
        $blocked = [ 'shell','exec','eval','server','package' ];
        $allowed = [ 'plugin','theme','core','db','option','post','user','term','cache',
                     'cron','transient','search-replace','rewrite','media','comment',
                     'menu','language','maintenance-mode','config','widget','sidebar' ];

        if ( in_array( $first, $blocked, true ) || ! in_array( $first, $allowed, true ) ) {
            return rest_ensure_response( $this->fail( "Comando '{$first}' no permitido." ) );
        }

        return rest_ensure_response( $this->cli( $command ) );
    }

    // ─────────────────────────────────────────────────────────
    //  DIVI ENGINE — HELPERS
    // ─────────────────────────────────────────────────────────

    private function is_divi_active(): bool {
        return get_template() === 'Divi';
    }

    private function validate_shortcodes( string $content ): bool {
        // Detect Divi 5 block format
        if ( strpos( $content, '<!-- wp:divi/' ) !== false ) {
            return $this->validate_divi5_blocks( $content );
        }

        // Detect Divi 4 shortcode format
        if ( strpos( $content, '[et_pb_' ) !== false ) {
            return $this->validate_divi4_shortcodes( $content );
        }

        return true;
    }

    private function validate_divi5_blocks( string $content ): bool {
        preg_match_all( '/<!-- wp:divi\/([^\s]+)/', $content, $open_matches );
        preg_match_all( '/<!-- \/wp:divi\/([^\s]+) -->/', $content, $close_matches );

        if ( empty( $open_matches[1] ) ) {
            return true;
        }

        $open_tags = array_reverse( $open_matches[1] );
        $close_tags = $close_matches[1];

        foreach ( $open_tags as $idx => $tag ) {
            if ( ! isset( $close_tags[ $idx ] ) || $close_tags[ $idx ] !== $tag ) {
                return false;
            }
        }
        return true;
    }

    private function validate_divi4_shortcodes( string $shortcodes ): bool {
        $open_tags = [];
        if ( preg_match_all( '/\[et_pb_([^\s\[]+)/', $shortcodes, $matches ) ) {
            foreach ( $matches[1] as $tag ) {
                $open_tags[] = $tag;
            }
        }
        $shortcodes_reversed = strrev( $shortcodes );
        foreach ( $open_tags as $tag ) {
            $close_pattern = '[/' . $tag . ']';
            if ( strpos( $shortcodes_reversed, strrev( $close_pattern ) ) === false ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Detect content format (Divi 5 block or Divi 4 shortcode)
     */
    private function detect_divi_format( string $content ): string {
        if ( strpos( $content, '<!-- wp:divi/' ) !== false ) {
            return 'd5_block';
        }
        if ( strpos( $content, '[et_pb_' ) !== false ) {
            return 'd4_shortcode';
        }
        return 'unknown';
    }

    // ─────────────────────────────────────────────────────────
    //  DIVI ENGINE — REST CALLBACKS
    // ─────────────────────────────────────────────────────────

    public function create_divi_page( WP_REST_Request $req ): WP_REST_Response {
        if ( ! $this->is_divi_active() ) {
            return rest_ensure_response( new WP_Error( 'divi_inactive', 'Divi not active — please activate Elegant Themes Divi first.', [ 'status' => 400 ] ) );
        }

        $prompt = sanitize_text_field( $req->get_param( 'prompt' ) );
        $title  = sanitize_text_field( $req->get_param( 'title' ) );
        $style  = sanitize_key( $req->get_param( 'style' ) ?: 'modern' );

        if ( empty( $prompt ) ) {
            return rest_ensure_response( new WP_Error( 'missing_param', 'Missing required parameter: prompt', [ 'status' => 400 ] ) );
        }

        $nlp  = new WP_MCP_NLP_Interpreter();
        $divi = new WP_MCP_Divi5_Generator();

        // ── Route to specialized generators based on page name detection ──
        $prompt_lower = mb_strtolower( $prompt );

        // Inicio / Home page
        if ( preg_match( '/\b(inicio|home|homepage|página\s+inicio)\b/', $prompt_lower ) ) {
            $content = $divi->generate_body_template( 'inicio' );
        }
        // Colecciones page
        elseif ( preg_match( '/\b(colecciones|collection)\b/', $prompt_lower ) ) {
            $content = $divi->generate_body_template( 'colecciones' );
        }
        // Contacto page
        elseif ( preg_match( '/\b(contacto|contact)\b/', $prompt_lower ) ) {
            $content = $divi->generate_body_template( 'contacto' );
        }
        // Nuestra Historia page
        elseif ( preg_match( '/\b(nuestra\s+historia|about\s+us|sobre\s+nosotros)\b/', $prompt_lower ) ) {
            $content = $divi->generate_body_template( 'nuestra-historia' );
        }
        // Login page
        elseif ( preg_match( '/\b(login|ingreso|iniciar\s+sesión)\b/', $prompt_lower ) ) {
            $content = $divi->generate_login_page();
        }
        // Default: NLP-based generation
        else {
            $layout_spec = $nlp->parse( $prompt );
            $design      = new WP_MCP_Design_System();
            $layout_spec = $design->apply_preset( $layout_spec, $style );
            $content     = $divi->generate_page( $layout_spec, $style );
        }

        if ( empty( trim( $content ) ) ) {
            $content = $this->block_fallback_content();
        }

        $page_id = wp_insert_post( [
            'post_type'    => 'page',
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
        ], true );

        if ( is_wp_error( $page_id ) ) {
            return rest_ensure_response( new WP_Error( 'page_creation_failed', $page_id->get_error_message(), [ 'status' => 500 ] ) );
        }

        return rest_ensure_response( [
            'success'      => true,
            'page_id'      => $page_id,
            'url'          => get_permalink( $page_id ),
            'divi_version' => 'd5',
            'format'       => 'block',
        ] );
    }

    /**
     * Fallback content when generator produces empty output
     */
    private function block_fallback_content(): string {
        $col = $this->block_fallback( 'column', '{"type":{"desktop":{"value":"4_4"}}}',
            $this->block_fallback( 'text', '{}', 'Contenido de la página' )
        );
        $row = $this->block_fallback( 'row', '{"modulePreset":"default"}', $col );
        return $this->block_fallback( 'section', '{"modulePreset":"default"}', $row );
    }

    private function block_fallback( string $module, string $attrs, string $inner = '' ): string {
        return "<!-- wp:divi/{$module} {$attrs} -->\n{$inner}\n<!-- /wp:divi/{$module} -->";
    }

    public function update_divi_theme_settings( WP_REST_Request $req ): WP_REST_Response {
        if ( ! $this->is_divi_active() ) {
            return rest_ensure_response( new WP_Error( 'divi_inactive', 'Divi is not the active theme. Activate Divi to use this endpoint.', [ 'status' => 400 ] ) );
        }

        $settings        = $req->get_param( 'settings' );
        $single_setting  = $req->get_param( 'setting' );
        $single_value    = $req->get_param( 'value' );

        $allowlist = [
            'et_divi[accent_color]',
            'et_divi[body_font]',
            'et_divi[heading_font]',
            'et_divi[primary_nav_background]',
            'et_divi[primary_nav_text_color]',
            'et_divi[button_style]',
            'et_divi[button_custom_padding]',
            'et_divi[logo_max_width]',
            'et_divi[section_padding_top]',
            'et_divi[section_padding_bottom]',
        ];

        // Batch update
        if ( is_array( $settings ) ) {
            foreach ( $settings as $idx => $item ) {
                if ( ! in_array( $item['setting'], $allowlist, true ) ) {
                    return rest_ensure_response( new WP_Error( 'invalid_setting', "Batch aborted: invalid setting at index {$idx}", [ 'status' => 400 ] ) );
                }
            }
            $count = 0;
            foreach ( $settings as $item ) {
                $key           = str_replace( [ 'et_divi[', ']' ], '', $item['setting'] );
                $divi_options  = get_option( 'et_divi', [] );
                $divi_options[ $key ] = $item['value'];
                update_option( 'et_divi', $divi_options );
                $count++;
            }
            return rest_ensure_response( [ 'success' => true, 'updated' => $count ] );
        }

        // Single update
        if ( $single_setting ) {
            if ( ! in_array( $single_setting, $allowlist, true ) ) {
                return rest_ensure_response( new WP_Error( 'invalid_setting', 'Setting key not in allowlist', [ 'status' => 400 ] ) );
            }
            $key           = str_replace( [ 'et_divi[', ']' ], '', $single_setting );
            $divi_options  = get_option( 'et_divi', [] );
            $divi_options[ $key ] = $single_value;
            update_option( 'et_divi', $divi_options );
            return rest_ensure_response( [ 'success' => true, 'setting' => $key, 'value' => $single_value ] );
        }

        // GET — return current settings
        $keys_param   = $req->get_param( 'keys' );
        $divi_options = get_option( 'et_divi', [] );
        if ( $keys_param ) {
            $keys     = array_map( 'sanitize_key', explode( ',', $keys_param ) );
            $filtered = [];
            foreach ( $keys as $k ) {
                $filtered[ $k ] = $divi_options[ $k ] ?? null;
            }
            return rest_ensure_response( [ 'success' => true, 'settings' => $filtered ] );
        }
        return rest_ensure_response( [ 'success' => true, 'settings' => $divi_options ] );
    }

    /**
     * Create a Divi Theme Builder Header template.
     * Saves as et_template post type so it can be assigned via Divi Theme Builder.
     *
     * POST /wp-json/mcp/v1/divi/header
     * Body: { "title": "LAIA Panama Header" }
     */
    public function create_divi_header_template( WP_REST_Request $req ): WP_REST_Response {
        if ( ! $this->is_divi_active() ) {
            return rest_ensure_response( new WP_Error( 'divi_inactive', 'Divi is not the active theme. Activate Divi to use this endpoint.', [ 'status' => 400 ] ) );
        }

        $title = sanitize_text_field( $req->get_param( 'title' ) ) ?: 'LAIA Panama Header';

        $divi = new WP_MCP_Divi5_Generator();
        $content = $divi->generate_header_template();

        if ( empty( trim( $content ) ) ) {
            return rest_ensure_response( new WP_Error( 'empty_template', 'Header template content was empty', [ 'status' => 500 ] ) );
        }

        // Save as et_template post type — Divi Library stores templates this way.
        // Divi Theme Builder can then assign these templates to header/footer/global sections.
        $template_id = wp_insert_post( [
            'post_type'    => 'et_template',
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
        ], true );

        if ( is_wp_error( $template_id ) ) {
            return rest_ensure_response( new WP_Error( 'template_creation_failed', $template_id->get_error_message(), [ 'status' => 500 ] ) );
        }

        // Store template type metadata so Theme Builder knows this is a header
        update_post_meta( $template_id, '_et_template_type', 'header' );

        return rest_ensure_response( [
            'success'       => true,
            'template_id'   => $template_id,
            'template_type' => 'header',
            'title'         => $title,
            'url'           => get_edit_post_link( $template_id, 'raw' ),
            'instructions'  => 'Go to Divi → Theme Builder → Use default/global header → Edit → Load from Library → Find "' . $title . '"',
        ] );
    }

    /**
     * Create a Divi Theme Builder Footer template.
     * Saves as et_template post type so it can be assigned via Divi Theme Builder.
     *
     * POST /wp-json/mcp/v1/divi/footer
     * Body: { "title": "LAIA Panama Footer" }
     */
    public function create_divi_footer_template( WP_REST_Request $req ): WP_REST_Response {
        if ( ! $this->is_divi_active() ) {
            return rest_ensure_response( new WP_Error( 'divi_inactive', 'Divi is not the active theme. Activate Divi to use this endpoint.', [ 'status' => 400 ] ) );
        }

        $title = sanitize_text_field( $req->get_param( 'title' ) ) ?: 'LAIA Panama Footer';

        $divi = new WP_MCP_Divi5_Generator();
        $content = $divi->generate_footer_template();

        if ( empty( trim( $content ) ) ) {
            return rest_ensure_response( new WP_Error( 'empty_template', 'Footer template content was empty', [ 'status' => 500 ] ) );
        }

        // Save as et_template post type — Divi Library stores templates this way.
        // Divi Theme Builder can then assign these templates to header/footer/global sections.
        $template_id = wp_insert_post( [
            'post_type'    => 'et_template',
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
        ], true );

        if ( is_wp_error( $template_id ) ) {
            return rest_ensure_response( new WP_Error( 'template_creation_failed', $template_id->get_error_message(), [ 'status' => 500 ] ) );
        }

        // Store template type metadata so Theme Builder knows this is a footer
        update_post_meta( $template_id, '_et_template_type', 'footer' );

        return rest_ensure_response( [
            'success'       => true,
            'template_id'   => $template_id,
            'template_type' => 'footer',
            'title'         => $title,
            'url'          => get_edit_post_link( $template_id, 'raw' ),
            'instructions'  => 'Go to Divi → Theme Builder → Use default/global footer → Edit → Load from Library → Find "' . $title . '"',
        ] );
    }

    /**
     * Create a Divi page BODY (no header/footer — those are assigned via Theme Builder).
     * Routes page name to the appropriate body generator.
     *
     * POST /wp-json/mcp/v1/divi/body
     * Body: { "page": "inicio", "title": "Inicio", "style": "modern" }
     */
    public function create_divi_body_page( WP_REST_Request $req ): WP_REST_Response {
        if ( ! $this->is_divi_active() ) {
            return rest_ensure_response( new WP_Error( 'divi_inactive', 'Divi is not the active theme. Activate Divi to use this endpoint.', [ 'status' => 400 ] ) );
        }

        $page  = sanitize_text_field( $req->get_param( 'page' ) );
        $title = sanitize_text_field( $req->get_param( 'title' ) ) ?: ucfirst( $page );
        $style = sanitize_key( $req->get_param( 'style' ) ?: 'modern' );

        if ( empty( $page ) ) {
            return rest_ensure_response( new WP_Error( 'missing_param', 'Missing required parameter: page', [ 'status' => 400 ] ) );
        }

        $divi   = new WP_MCP_Divi5_Generator();
        $content = $divi->generate_body_template( $page );

        if ( empty( trim( $content ) ) ) {
            return rest_ensure_response( new WP_Error( 'empty_content', "No body generator found for page: {$page}", [ 'status' => 500 ] ) );
        }

        $page_id = wp_insert_post( [
            'post_type'    => 'page',
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
        ], true );

        if ( is_wp_error( $page_id ) ) {
            return rest_ensure_response( new WP_Error( 'page_creation_failed', $page_id->get_error_message(), [ 'status' => 500 ] ) );
        }

        // Add Divi 5 meta keys so Divi knows to use the builder
        update_post_meta( $page_id, '_et_pb_use_builder', 'on' );
        update_post_meta( $page_id, '_et_pb_use_divi_5', 'on' );
        update_post_meta( $page_id, '_et_pb_show_page_creation', 'off' );

        return rest_ensure_response( [
            'success'      => true,
            'page_id'      => $page_id,
            'url'          => get_permalink( $page_id ),
            'divi_version' => 'd5',
            'format'       => 'block',
            'body_page'    => $page,
            'note'         => 'Assign Header Global and Footer Global from Divi → Theme Builder after creating them with wp_create_divi_header and wp_create_divi_footer',
        ] );
    }

    /**
     * Create a Divi page body using DIRECT database write to bypass block editor.
     * WordPress's block editor corrupts wp:divi/* blocks, so we write raw content
     * directly via $wpdb to preserve the original format.
     *
     * POST /wp-json/mcp/v1/divi/body-raw
     * Body: { "page": "inicio", "title": "Inicio", "style": "modern" }
     */
    public function create_divi_body_raw( WP_REST_Request $req ): WP_REST_Response {
        if ( ! $this->is_divi_active() ) {
            return rest_ensure_response( new WP_Error( 'divi_inactive', 'Divi is not the active theme. Activate Divi to use this endpoint.', [ 'status' => 400 ] ) );
        }

        global $wpdb;

        $page  = sanitize_text_field( $req->get_param( 'page' ) );
        $title = sanitize_text_field( $req->get_param( 'title' ) ) ?: ucfirst( $page );
        $style = sanitize_key( $req->get_param( 'style' ) ?: 'modern' );

        if ( empty( $page ) ) {
            return rest_ensure_response( new WP_Error( 'missing_param', 'Missing required parameter: page', [ 'status' => 400 ] ) );
        }

        $divi   = new WP_MCP_Divi5_Generator();
        $content = $divi->generate_body_template( $page );

        if ( empty( trim( $content ) ) ) {
            return rest_ensure_response( new WP_Error( 'empty_content', "No body generator found for page: {$page}", [ 'status' => 500 ] ) );
        }

        $now = current_time( 'mysql' );
        $slug = sanitize_title( $title );

        $result = $wpdb->insert(
            $wpdb->posts,
            [
                'post_type'    => 'page',
                'post_title'   => $title,
                'post_name'    => $slug,
                'post_content' => $content,
                'post_status'  => 'publish',
                'post_author'  => get_current_user_id(),
                'post_date'   => $now,
                'post_date_gmt' => get_gmt_from_date( $now ),
                'post_modified' => $now,
                'post_modified_gmt' => get_gmt_from_date( $now ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
        );

        if ( $result === false ) {
            return rest_ensure_response( new WP_Error( 'db_insert_failed', 'Failed to insert post: ' . $wpdb->last_error, [ 'status' => 500 ] ) );
        }

        $page_id = $wpdb->insert_id;
        clean_post_cache( $page_id );

        // Add Divi 5 meta keys so Divi knows to use the builder
        update_post_meta( $page_id, '_et_pb_use_builder', 'on' );
        update_post_meta( $page_id, '_et_pb_use_divi_5', 'on' );
        update_post_meta( $page_id, '_et_pb_show_page_creation', 'off' );

        return rest_ensure_response( [
            'success'      => true,
            'page_id'      => $page_id,
            'url'          => get_permalink( $page_id ),
            'divi_version' => 'd5',
            'format'       => 'block_raw',
            'body_page'    => $page,
            'note'         => 'Content written directly to DB to preserve Divi 5 block format. Assign Header/Footer from Theme Builder.',
        ] );
    }

    /**
     * Create a Divi header/footer template using DIRECT database write.
     * Bypasses block editor to preserve wp:divi/* block format.
     *
     * POST /wp-json/mcp/v1/divi/template-raw
     * Body: { "type": "header|footer", "title": "..." }
     */
    public function create_divi_template_raw( WP_REST_Request $req ): WP_REST_Response {
        if ( ! $this->is_divi_active() ) {
            return rest_ensure_response( new WP_Error( 'divi_inactive', 'Divi is not the active theme. Activate Divi to use this endpoint.', [ 'status' => 400 ] ) );
        }

        global $wpdb;

        $type  = sanitize_text_field( $req->get_param( 'type' ) );
        $title = sanitize_text_field( $req->get_param( 'title' ) ) ?: 'LAIA Panama ' . ucfirst( $type);
        $style = sanitize_key( $req->get_param( 'style' ) ?: 'modern' );

        if ( empty( $type ) || ! in_array( $type, [ 'header', 'footer' ], true ) ) {
            return rest_ensure_response( new WP_Error( 'invalid_type', 'Type must be "header" or "footer"', [ 'status' => 400 ] ) );
        }

        $divi    = new WP_MCP_Divi5_Generator();
        $content = $type === 'header' ? $divi->generate_header_template() : $divi->generate_footer_template();

        if ( empty( trim( $content ) ) ) {
            return rest_ensure_response( new WP_Error( 'empty_template', 'Template content was empty', [ 'status' => 500 ] ) );
        }

        $now = current_time( 'mysql' );
        $slug = sanitize_title( $title );

        $result = $wpdb->insert(
            $wpdb->posts,
            [
                'post_type'    => 'et_template',
                'post_title'   => $title,
                'post_name'    => $slug,
                'post_content' => $content,
                'post_status'  => 'publish',
                'post_author'  => get_current_user_id(),
                'post_date'   => $now,
                'post_date_gmt' => get_gmt_from_date( $now ),
                'post_modified' => $now,
                'post_modified_gmt' => get_gmt_from_date( $now ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
        );

        if ( $result === false ) {
            return rest_ensure_response( new WP_Error( 'db_insert_failed', 'Failed to insert template: ' . $wpdb->last_error, [ 'status' => 500 ] ) );
        }

        $template_id = $wpdb->insert_id;
        update_post_meta( $template_id, '_et_template_type', $type );
        clean_post_cache( $template_id );

        return rest_ensure_response( [
            'success'       => true,
            'template_id'   => $template_id,
            'template_type' => $type,
            'title'         => $title,
            'url'           => get_edit_post_link( $template_id, 'raw' ),
            'instructions'   => 'Go to Divi → Theme Builder → Use default/global ' . $type . ' → Edit → Load from Library → Find "' . $title . '"',
        ] );
    }

    /**
     * Update existing page content using DIRECT database write.
     * Bypasses block editor to preserve wp:divi/* block format.
     *
     * POST /wp-json/mcp/v1/divi/update-content
     * Body: { "post_id": 123, "content": "..." }
     */
    public function update_page_content_raw( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;

        $post_id = (int) $req->get_param( 'post_id' );
        $content = $req->get_param( 'content' );

        if ( empty( $post_id ) || empty( $content ) ) {
            return rest_ensure_response( new WP_Error( 'missing_params', 'post_id and content are required', [ 'status' => 400 ] ) );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return rest_ensure_response( new WP_Error( 'post_not_found', "Post ID {$post_id} not found", [ 'status' => 404 ] ) );
        }

        $now = current_time( 'mysql' );

        $result = $wpdb->update(
            $wpdb->posts,
            [
                'post_content'   => $content,
                'post_modified'  => $now,
                'post_modified_gmt' => get_gmt_from_date( $now ),
            ],
            [ 'ID' => $post_id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );

        if ( $result === false ) {
            return rest_ensure_response( new WP_Error( 'db_update_failed', 'Failed to update post: ' . $wpdb->last_error, [ 'status' => 500 ] ) );
        }

        clean_post_cache( $post_id );

        return rest_ensure_response( [
            'success'  => true,
            'post_id'  => $post_id,
            'url'      => get_permalink( $post_id ),
            'format'   => 'block_raw',
        ] );
    }

    public function optimize_divi_page( WP_REST_Request $req ): WP_REST_Response {
        $post_id = (int) $req->get_param( 'post_id' );
        $targets = $req->get_param( 'optimization_targets' ) ?: ['speed', 'seo', 'accessibility'];
        $preview = (bool) $req->get_param( 'preview' );

        $post = get_post( $post_id );
        if ( ! $post ) {
            return rest_ensure_response( new WP_Error( 'not_divi_page', "Post ID {$post_id} not found", [ 'status' => 400 ] ) );
        }

        $content = $post->post_content;
        $format = $this->detect_divi_format( $content );

        if ( $format === 'unknown' ) {
            return rest_ensure_response( new WP_Error( 'not_divi_page', "Post {$post_id} does not appear to be a Divi page (no Divi 4 or Divi 5 content detected)", [ 'status' => 400 ] ) );
        }

        $cro = new WP_MCP_CRO_Engine();

        if ( $preview ) {
            $scores = $cro->calculate_scores( $content );
            return rest_ensure_response( [
                'success'          => true,
                'preview'          => true,
                'scores'           => $scores,
                'proposed_changes' => $this->get_proposed_changes( $content, $targets, $format ),
                'format'           => $format,
            ] );
        }

        $result = $cro->apply_optimizations( $post_id, $targets, $format );

        $log = get_post_meta( $post_id, '_divi_optimization_log', true ) ?: [];
        $log[] = [
            'timestamp'      => gmdate( 'c' ),
            'targets'       => $targets,
            'scores_before' => $result['scores_before'],
            'scores_after'  => $result['scores_after'],
            'changes'       => $result['optimizations_applied'],
            'format'        => $format,
        ];
        update_post_meta( $post_id, '_divi_optimization_log', $log );

        return rest_ensure_response( $result );
    }

    private function get_proposed_changes( string $content, array $targets, string $format = 'd4_shortcode' ): array {
        $changes = [];
        if ( $format === 'd5_block' ) {
            // Divi 5 block format detection
            if ( in_array( 'speed', $targets, true ) ) {
                if ( strpos( $content, '"loading"' ) === false && strpos( $content, '"src"' ) !== false ) {
                    $changes[] = 'lazy_load_images';
                }
                $changes[] = 'minify_css';
            }
            if ( in_array( 'seo', $targets, true ) ) {
                if ( preg_match( '/wp:divi\/image(?!.*"alt")/', $content ) ) {
                    $changes[] = 'add_missing_alts';
                }
                $changes[] = 'heading_hierarchy';
            }
            if ( in_array( 'accessibility', $targets, true ) ) {
                if ( substr_count( $content, '"headingLevel":"h1"' ) > 1 ) {
                    $changes[] = 'fix_heading_order';
                }
            }
        } else {
            // Divi 4 shortcode format detection
            if ( in_array( 'speed', $targets, true ) ) {
                if ( strpos( $content, 'loading=' ) === false ) {
                    $changes[] = 'lazy_load_images';
                }
                $changes[] = 'minify_css';
            }
            if ( in_array( 'seo', $targets, true ) ) {
                if ( preg_match( '/\[et_pb_image(?!.*alt=)/', $content ) ) {
                    $changes[] = 'add_missing_alts';
                }
                $changes[] = 'heading_hierarchy';
            }
            if ( in_array( 'accessibility', $targets, true ) ) {
                if ( substr_count( $content, 'header_level="h1"' ) > 1 ) {
                    $changes[] = 'fix_heading_order';
                }
            }
        }
        return $changes;
    }

    public function analyze_divi_page( WP_REST_Request $req ): WP_REST_Response {
        $post_id       = (int) $req->get_param( 'post_id' );
        $force_refresh = (bool) $req->get_param( 'force_refresh' );

        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_REST_Response(
                [ 'success' => false, 'error' => "Post ID {$post_id} not found" ],
                400
            );
        }

        $content = $post->post_content;
        $format  = $this->detect_divi_format( $content );

        if ( $format === 'unknown' ) {
            return new WP_REST_Response(
                [ 'success' => false, 'error' => "Post {$post_id} does not appear to be a Divi page" ],
                400
            );
        }

        // Check cache
        if ( ! $force_refresh ) {
            $cached = get_post_meta( $post_id, '_divi_analysis_cache', true );
            if ( $cached ) {
                $cached_data = maybe_unserialize( $cached );
                $cache_age   = time() - ( $cached_data['cached_at'] ?? 0 );
                if ( $cache_age < 86400 ) {
                    $cached_data['cached']          = true;
                    $cached_data['cache_age_hours'] = round( $cache_age / 3600, 1 );
                    return rest_ensure_response( $cached_data );
                }
            }
        }

        $cro     = new WP_MCP_CRO_Engine();
        $scores  = $cro->calculate_scores( $content, $format );
        $overall = (int) ( ( $scores['ux'] + $scores['seo'] + $scores['performance'] ) / 3 );
        $issues  = [];

        if ( $format === 'd5_block' ) {
            // Divi 5 block format issues
            if ( preg_match( '/wp:divi\/image(?!.*"alt")/', $content ) ) {
                $issues[] = [ 'severity' => 'warning', 'element' => 'missing_alt_images', 'suggestion' => 'Add descriptive alt text to all images' ];
            }
            if ( substr_count( $content, '"headingLevel":"h1"' ) > 1 ) {
                $issues[] = [ 'severity' => 'warning', 'element' => 'heading_structure', 'suggestion' => 'Consider using only one H1 per page' ];
            }
            if ( strpos( $content, 'wp:divi/cta' ) === false && strpos( $content, 'wp:divi/button' ) === false ) {
                $issues[] = [ 'severity' => 'info', 'element' => 'no_cta_found', 'suggestion' => 'Consider adding a call-to-action section' ];
            }
        } else {
            // Divi 4 shortcode format issues
            if ( preg_match( '/\[et_pb_image(?!.*alt=)/', $content ) ) {
                $issues[] = [ 'severity' => 'warning', 'element' => 'missing_alt_images', 'suggestion' => 'Add descriptive alt text to all images' ];
            }
            if ( substr_count( $content, 'header_level="h1"' ) > 1 ) {
                $issues[] = [ 'severity' => 'warning', 'element' => 'heading_structure', 'suggestion' => 'Consider using only one H1 per page' ];
            }
            if ( strpos( $content, 'et_pb_cta' ) === false && strpos( $content, 'et_pb_button' ) === false ) {
                $issues[] = [ 'severity' => 'info', 'element' => 'no_cta_found', 'suggestion' => 'Consider adding a call-to-action section' ];
            }
        }

        $result = [
            'success'    => true,
            'post_id'    => $post_id,
            'scores'     => array_merge( $scores, [ 'overall' => $overall ] ),
            'issues'     => $issues,
            'format'     => $format,
            'summary'    => $this->generate_summary( $overall, $issues ),
        ];

        update_post_meta( $post_id, '_divi_analysis_cache', maybe_serialize( array_merge( $result, [ 'cached_at' => time() ] ) ) );

        return rest_ensure_response( $result );
    }

    private function generate_summary( int $overall, array $issues ): string {
        if ( $overall >= 80 ) {
            return 'Page scores above average. Primary improvement opportunity: SEO meta elements.';
        }
        if ( $overall >= 60 ) {
            return 'Page scores in average range. Consider adding more CTA elements and improving image alt attributes.';
        }
        return 'Page needs significant improvements. Focus on heading hierarchy, CTA presence, and performance optimization.';
    }
}

new WP_MCP_Bridge();
