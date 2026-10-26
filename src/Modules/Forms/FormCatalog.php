<?php
namespace Fabrement\Modules\Forms;

/** The site's Contact Form 7 forms. */
final class FormCatalog {
    public static function is_cf7_active(): bool {
        return class_exists( \WPCF7_ContactForm::class );
    }

    public static function collect_contact_forms(): array {
        if ( ! self::is_cf7_active() ) {
            return [];
        }

        $out = [];
        foreach ( \WPCF7_ContactForm::find() as $form ) {
            $id = (int) $form->id();
            if ( $id < 1 ) {
                continue;
            }
            $html = (string) $form->prop( 'form' );
            if ( strlen( $html ) > 50000 ) {
                continue;
            }
            $out[] = [
                'id'    => $id,
                'title' => (string) $form->title(),
                'html'  => $html,
            ];
            if ( count( $out ) >= 24 ) {
                break;
            }
        }

        return $out;
    }
}
