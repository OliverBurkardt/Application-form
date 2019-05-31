<?php

require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Returns a file size limit in bytes based on the PHP upload_max_filesize
 * and post_max_size.
 */
function file_upload_max_size() {
  static $max_size = -1;

  if ($max_size < 0) {
    // Start with post_max_size.
    $post_max_size = parse_size(ini_get('post_max_size'));
    if ($post_max_size > 0) {
      $max_size = $post_max_size;
    }

    // If upload_max_size is less, then reduce. Except if upload_max_size is
    // zero, which indicates no limit.
    $upload_max = parse_size(ini_get('upload_max_filesize'));
    if ($upload_max > 0 && $upload_max < $max_size) {
      $max_size = $upload_max;
    }
  }
  return $max_size;
}

function parse_size($size) {
  $unit = preg_replace('/[^bkmgtpezy]/i', '', $size); // Remove the non-unit characters from the size.
  $size = preg_replace('/[^0-9\.]/', '', $size);      // Remove the non-numeric characters from the size.
  if ($unit) {
    // Find the position of the unit in the ordered string which is the power of magnitude to multiply a kilobyte by.
    return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
  }
  else {
    return round($size);
  }
}

function format_bytes($size, $precision = 2) {
  $base = log($size, 1024);
  $suffixes = array('', 'KB', 'MB', 'GB', 'TB');
  return round(pow(1024, $base - floor($base)), $precision).' '.$suffixes[floor($base)];
}

/**
 * Encode items as CSV text.
 * 
 * @param array $items
 * @return string CSV text
 */
function to_csv($data) {
  $csv = fopen('php://temp', 'r+');
  foreach ($data as $fields) {
    fputcsv($csv, $fields);
  }
  rewind($csv);
  $content = stream_get_contents($csv);
  fclose($csv);
  return $content;
}


/**
 * Sanitize a filename with given extension.
 * 
 * @param string $filename
 * @param string $extension
 * @return string Sanitized filename 
 */
function sanitize_filename($filename, $extension) {
  return
    preg_replace("/[^A-Za-z0-9\-_]/", "", $filename).
    '.'.
    preg_replace("/[^A-Za-z0-9\-_]/", "", $extension);
}

/**
 * Create registration PDF document.
 * 
 * @param array $items
 * ...
 */
function create_pdf($fieldsets, $items, $inline = false, $logo = 'resources/logo.jpg') {
  $mpdf = new \Mpdf\Mpdf(['format' => 'A4-L']);
  $mpdf->ignore_table_percents = false;
  $mpdf->use_kwt = true;
  
  $mpdf->imageVars['logo'] = file_get_contents($logo);
  $mpdf->imageVars['bewerbungsfoto'] = file_get_contents($items['bewerbungsfoto']['value']['tmp_name']);
  $mpdf->imageVars['familienfoto'] = file_get_contents($items['familienfoto']['value']['tmp_name']);
  $mpdf->imageVars['hobbyfoto'] = file_get_contents($items['hobbyfoto']['value']['tmp_name']);

  $mpdf->SHYlang = 'de';
  
  // Style:
  $mpdf->WriteHTML(
    "body { font-family: dejavusans; }".
    "h2 { font-size: 15px; margin-bottom: 0px; }".
    "table { width: 100%; overflow: wrap; padding: 0; border-spacing: 0; }".
    "tr:nth-child(even) { background: #CCCCCC; }".
    "td { text-align: justify;  hyphens: auto; vertical-align: top; margin-top: 5px; }".
    "td:nth-child(1) { width: 40%; padding-right: 20px; }"
  , 1);
  

  // HTML:
  $mpdf->setAutoTopMargin = 'stretch';
  $mpdf->setAutoBottomMargin = 'stretch';
  $mpdf->SetHTMLHeader(
    '<table width="100%">
      <tr>
        <td align="left"></td>
        <td align="center"></td>
        <td align="right">{PAGENO}/{nbpg}</td>
      </tr>
     </table>'
  );
  $mpdf->SetHTMLFooter(
    '<div style="text-align: center; font-weight: bold;">
       <sub>Amigos de la Cultura e.V. | Franz-Liszt-Straße 4 | 01219 Dresden | Register: Amtsgericht Dresden VR 7759</sub>
     </div>'
  );

  $mpdf->WriteHTML(
    "<img src='var:logo' style='float: left;' width='80' height='80'>".
    "<div style='padding-left: 20px; padding-top: 20px; font-weight: bold; font-size: 200%' >
      SCHÜLERBOGEN<br>
     </div>".
    "<p>
     <img style='max-height: 260px; padding: 1em' src='var:bewerbungsfoto'> 
     <img style='max-height: 260px; padding: 1em' src='var:familienfoto'>
    </p>"
  );

  $text_items = form_items_to_text($fieldsets, $items, [
  'zeitraum',
  'entscheidung',
  'arbeitgeber_mutter',
  'arbeitgeber_vater',
  'gastfamilie_vorstellung',
  'entscheidung',
  'bekannte',
  'vorname_bekannte',
  'nachname_bekannte',
  'festnetznummer_bekannte',
  'mobilfunknummer_bekannte',
  'straße_hausnummer_bekannte',
  'wohnort_bekannte',
  'land_bekannte',
  'email_bekannte',
  'gastfamilie',
  'vorname_gastfamilie',
  'nachname_gastfamilie',
  'festnetznummer_gastfamilie',
  'mobilfunknummer_gastfamilie',
  'straße_hausnummer_gastfamilie',
  'wohnort_gastfamilie',
  'land_gastfamilie',
  'email_gastfamilie'
  ]);
  
  // print fieldsets:
  foreach ($fieldsets as $fieldset_name => $fieldset) {
    
    // print legend header:
    // @XXX: Change 'allgemein' label to "Meine Familie und ich" for PDF output:
    if ($fieldset_name == 'allgemein') {
      $mpdf->WriteHTML("<h2>Meine Familie und ich</h2>");
    } else {
      $mpdf->WriteHTML("<h2>{$fieldset['legend'][0]}</h2>");
    }
    
    $fieldset_text_items = array_intersect_key($text_items, array_flip($fieldset['items']));

    $rows = implode('', array_map(function($name) use($items, $fieldset_text_items) {
      $nicename = htmlspecialchars($items[$name]['name'], ENT_QUOTES);
      
      // @XXX: Change 'zeitraum' values such as "Programa Julio / 26.07.2018 - 02.01.2019​ (15-17 años)" to just "6.07.2018 - 02.01.2019​" for PDF output:
      if ($name == 'zeitraum') {
        preg_match('/[\d\.]+ - [\d\.]+/', $fieldset_text_items[$name], $match);
        $value = htmlspecialchars($match[0], ENT_QUOTES);
      } else {
        $value = htmlspecialchars($fieldset_text_items[$name], ENT_QUOTES);
      }
      return "<tr><td>{$nicename}</td><td>{$value}</td></tr>";
    }, array_keys($fieldset_text_items)));
    
    $mpdf->WriteHTML("<table>{$rows}</table>");
  }
  
  $mpdf->WriteHTML(
    "<div style='text-align: center;'>
    <img style='max-height: 260px; padding: 1em;' src='var:hobbyfoto'>
    </div>"
  );

  return $inline ? $mpdf->Output('preview.pdf', \Mpdf\Output\Destination::INLINE) : $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
}

/**
 * Send registration email.
 * 
 * @param string $from
 * ...
 * 
 * $to = 'info@amigos-cultura.de', $from = 'bewerbung@amigos-cultura.de'
 * 
 */
function send_registration_mail($fieldsets, $items, $to = 'info@amigos-cultura.de', $from = 'bewerbung@amigos-cultura.de') {
  $mail = new PHPMailer(true);
  $mail->CharSet = 'UTF-8';

  // sender and recipients:
  $mail->setFrom($from, 'Solicitud');
  $mail->addAddress($to);

  // attachments:
  $pdf = create_pdf($fieldsets, $items);
  $pdf_filename = sanitize_filename('Schuelerbogen_'.$items['vorname']['value'], 'pdf');
  $mail->AddStringAttachment($pdf, $pdf_filename);

  $text_items = form_items_to_text($fieldsets, $items);
  $csv = to_csv([array_keys($text_items), array_values($text_items)]);
  
  $csv_filename = sanitize_filename($items['vorname']['value'].'_'.$items['nachname']['value'], '.csv');
  $mail->AddStringAttachment($csv, $csv_filename);
  
  $bewerbungsfoto_filename = sanitize_filename($items['vorname']['value'].'_'.$items['nachname']['value'].'_portrait', pathinfo($items['bewerbungsfoto']['value']['name'], PATHINFO_EXTENSION));
  $mail->AddAttachment($items['bewerbungsfoto']['value']['tmp_name'], $bewerbungsfoto_filename);
  $hobbyfoto_filename = sanitize_filename($items['vorname']['value'].'_'.$items['nachname']['value'].'_hobbyfoto', pathinfo($items['hobbyfoto']['value']['name'], PATHINFO_EXTENSION));
  $mail->AddAttachment($items['hobbyfoto']['value']['tmp_name'], $hobbyfoto_filename);
  $familienfoto_filename = sanitize_filename($items['vorname']['value'].'_'.$items['nachname']['value'].'_familie', pathinfo($items['familienfoto']['value']['name'], PATHINFO_EXTENSION));
  $mail->AddAttachment($items['familienfoto']['value']['tmp_name'], $familienfoto_filename);
  $zeugnis_filename = sanitize_filename($items['vorname']['value'].'_'.$items['nachname']['value'].'_zeugnis', pathinfo($items['zeugnis']['value']['name'], PATHINFO_EXTENSION));
  $mail->AddAttachment($items['zeugnis']['value']['tmp_name'], $zeugnis_filename);

  // content:
  $mail->isHTML(false);
  $mail->Subject = "Bewerbung von {$items['vorname']['value']} {$items['nachname']['value']}";
  
  foreach ($fieldsets as $fieldset) {
    
    // print fieldset legend:
    $mail->Body .= "{$fieldset['legend'][0]}:\n";
    
    // print fieldset items:
    $fieldset_text_items = array_intersect_key($text_items, array_flip($fieldset['items']));
    foreach ($fieldset_text_items as $name => $value) {
      $nicename = str_pad($items[$name]['name'], 20);
      $mail->Body .= " • {$nicename}: {$value}\n";
    }
    $mail->Body .= "\n";
  }
    
  $mail->send();
}

/* Send confirmation email @Student */
function send_confirmation_student_mail($fieldsets, $items, $from = 'info@amigos-cultura.de') {
  $mail = new PHPMailer();
  $mail->CharSet = 'UTF-8';
  
  $mail->setFrom($from);
  $mail->addAddress($items['email']['value']);


  $mail->isHTML(false);
  $mail->Subject = "Formulario de Solicitud - Amigos de la Cultura e.V.";
  $mail->Body = 
"Hallo {$items['vorname']['value']} {$items['nachname']['value']},

Hiermit bestätigen wir den Eingang Deines Online-Bewerbungsformulars.

Tengo a bien confirmar la recepción del formulario de Solicitud online.

Viele Grüße,

Das Team von Amigos de la Cultura e.V.

Tu amigo de I N T E R C A M B I O !!!

https://www.facebook.com/amigosculturaintercambio/";

  $mail->send();
}

/* Send confirmation email @Mother */
function send_confirmation_mother_mail($fieldsets, $items, $from = 'info@amigos-cultura.de') {
  $mail = new PHPMailer();
  $mail->CharSet = 'UTF-8';
  
  $mail->setFrom($from);
  $mail->addAddress($items['email_mutter']['value']);


  $mail->isHTML(false);
  $mail->Subject = "Formulario de Solicitud - Amigos de la Cultura e.V.";
  $mail->Body = 
"Sehr geehrte Frau {$items['nachname_mutter']['value']},

Hiermit bestätigen wir den Eingang des Online-Bewerbungsformulars von {$items['vorname']['value']} {$items['nachname']['value']}.

Tengo a bien confirmar la recepción del formulario de Solicitud online de {$items['vorname']['value']} {$items['nachname']['value']}.

Viele Grüße,

Das Team von Amigos de la Cultura e.V.

Tu amigo de I N T E R C A M B I O !!!

https://www.facebook.com/amigosculturaintercambio/";

  $mail->send();
}

/* Send confirmation email @Father */
function send_confirmation_father_mail($fieldsets, $items, $from = 'info@amigos-cultura.de') {
  $mail = new PHPMailer();
  $mail->CharSet = 'UTF-8';
  
  $mail->setFrom($from);
  $mail->addAddress($items['email_vater']['value']);


  $mail->isHTML(false);
  $mail->Subject = "Formulario de Solicitud - Amigos de la Cultura e.V.";
  $mail->Body = 
"Sehr geehrter Herr {$items['nachname_vater']['value']},

Hiermit bestätigen wir den Eingang des Online-Bewerbungsformulars von {$items['vorname']['value']} {$items['nachname']['value']}.

Tengo a bien confirmar la recepción del formulario de Solicitud online de {$items['vorname']['value']} {$items['nachname']['value']}.

Viele Grüße,

Das Team von Amigos de la Cultura e.V.

Tu amigo de I N T E R C A M B I O !!!

https://www.facebook.com/amigosculturaintercambio/";

  $mail->send();
}

function html_attributes($attributes) {
  return implode(' ', array_map(function($key, $value) {
    if (is_bool($value)) {
      return $key;
    } else {
      $value = htmlspecialchars($value, ENT_QUOTES);
      return "{$key}='{$value}'";
    }
  }, array_keys($attributes), $attributes));
}

function form_items_to_text($fieldsets, $items, $exclude = []) {
  $text_items = [];
  foreach ($fieldsets as $fieldset) {
    foreach ($fieldset['items'] as $name) {
	  if (in_array($name, $exclude)) continue;
	  
      $item = $items[$name];
      
      // skip items whose condition is false:
      if (isset($item['condition']) && !$item['condition']['result']) continue;
      
      // convert item value to text if possible:
      if (in_array($item['type'], ['addr', 'text', 'textarea', 'textarea2', 'select', 'email', 'tel', 'tel2'])) {
        $text_items[$name] = $item['value'];
      } else if (in_array($item['type'], ['multiselect'])) {
        $text_items[$name] = implode(', ', $item['value']);
      } else if (in_array($item['type'], ['date'])) {
        $text_items[$name] = implode('.', $item['value']);
      }
    }
  }
  return $text_items;
}

/**
 * Check if the given item's value is a non-empty string. Returns an
 * array of error messages which is empty on successful validation.
 * 
 * @param array $item
 * @param array $errors 
 * @return array
 */
function validate_text($name, &$item) {
  if ($item['value'] == '') {
    $item['error'] = 'Feld darf nicht leer sein!';
  }
}

//Syntax korrigieren, Mechanismus ergründen
function validate_textarea2($name, &$item) {
  if ($item['value'] = '') {
	  $item['value'] = true;
  }
}



function validate_tel($name, &$item) {
  if ($item['value'] === '+') {
    $item['error'] = 'Feld darf nicht leer sein!';
  }
}

function validate_tel2($name, &$item) {
  if ($item['value'] === '+') {
    $item['error'] = 'Feld darf nicht leer sein!';
  }
}

/**
 * Check if the given item's value is an email address. Returns an
 * array of error messages which is empty on successful validation.
 * 
 * @param array $item
 * @param array $errors 
 * @return array
 */
function validate_email($name, &$item) {
  if (!filter_var($item['value'], FILTER_VALIDATE_EMAIL)) {
    $item['error'] = 'Bitte gebe eine gültige E-Mail-Adresse ein!';
  }
}

/**
 * Check if the given item's value is a valid image file. Returns an
 * array of error messages which is empty on successful validation.
 * 
 * @param array $item
 * @param array $errors 
 * @return array
 */
function validate_file($name, &$item) {
  if (empty($item['value']) || $item['value']['error'] == UPLOAD_ERR_NO_FILE) {
    $item['error'] = 'Bitte lade eine Datei hoch!';
  } else if ($item['value']['error'] == UPLOAD_ERR_INI_SIZE || $item['value']['error'] == UPLOAD_ERR_FORM_SIZE) {
    $item['error'] = 'Die Datei ist größer als erlaubt (maximal '.format_bytes(file_upload_max_size()).')!';
  } else if ($item['value']['error'] != UPLOAD_ERR_OK) {
    $item['error'] = 'Der Dateiupload ist fehlgeschlagen. Bitte probiere es noch einmal.';
  } else if (!in_array(strtolower(pathinfo($item['value']['name'], PATHINFO_EXTENSION)), $item['extensions'])) {
    $item['error'] = 'El archivo debe tener una de las siguientes extensiones de archivo: '.implode(', ', $item['extensions']);
  }
}

/**
 * Check if the given item's value is a valid choice. Returns an
 * array of error messages which is empty on successful validation.
 * 
 * @param array $item
 * @param array $errors 
 * @return array
 */
function validate_select($name, &$item) {
  if (!in_array($item['value'], $item['choices'])) {
    $item['error'] = 'Bitte treffe eine gültige Auswahl - Por favor, realice una selección válida!';
  }
}


function validate_multiselect($name, &$item) {
  if (!in_array($item['value'], $item['choices'])) {
    $item['error'] = 'Bitte treffe eine gültige Auswahl - Por favor, realice una selección válida!';
  }
}

/**
 * Check if the given item's value is a valid date. Returns an
 * array of error messages which is empty on successful validation.
 * 
 * @param array $item
 * @param array $errors 
 * @return array
 */
function validate_date($name, &$item) {
  if (!isset($item['value']['month']) || !isset($item['value']['day']) || !isset($item['value']['year'])) {
    $item['error'] = 'Bitte gebe ein vollständiges Datum an!';
  } else if (!checkdate($item['value']['month'], $item['value']['day'], $item['value']['year'])) {
    $item['error'] = 'Bitte gebe ein gültiges Datum an!';
  }
}

/**
 * Check if the given item's values are valid according to their
 * validator function. Returns an array of error messages which is
 * empty on successful validation.
 * 
 * @param array $item
 * @param array $errors 
 * @return array
 */
function validate_form_items($fieldsets, &$items) {
  $success = true;
  foreach ($fieldsets as $fieldset) {
    foreach ($fieldset['items'] as $name) {
      $item = &$items[$name];
      
      // Skip all items whose condition is not fulfilled:
      if (isset($item['condition'])) {
        $master = $items[$item['condition']['name']];
        if (!in_array($master['value'], $item['condition']['values'])) {
          $item['condition']['result'] = false;
          continue;
        } else {
          $item['condition']['result'] = true;
        }
      }
      
      // Validate item using its validate function:
      if (isset($item['validate'])) {
        $validator = $item['validate'];
        $validator($name, $item);
      }
      
      if (isset($item['error'])) $success = false;
    }
  }
  return $success;
}

/**
 * Initialize form items from request parameters found in $_POST or 
 * $_FILES depending on the respective item's type.
 * 
 * @param array $items
 * @return array
 */
function initialize_form_items(&$items) {
  foreach ($items as $name => &$item) {
    switch ($item['type']) {
      case 'select':
        $value = isset($_POST[$name]) && in_array($_POST[$name], $item['choices']) ? $_POST[$name] : '';
        $item['value'] = $value;
        break;
      case 'multiselect':
        $value = isset($_POST[$name]) && array_key_exists($_POST[$name], $item['choices']) ?  $item['choices'][$_POST[$name]] : '';
        $item['value'] = $value;
        break;
      case 'file':
        $value = isset($_FILES[$name]) ? $_FILES[$name] : [];
        $item['value'] = $value;
        break;
      case 'date':
        $value = isset($_POST[$name]) && is_array($_POST[$name]) ? $_POST[$name] : [];
        $item['value'] = $value;
        break;
      case 'tel':
        $value = isset($_POST[$name]) ? trim($_POST[$name]) : '+';
        $item['value'] = $value;
        break;
      case 'tel2':
        $value = isset($_POST[$name]) ? trim($_POST[$name]) : '+';
        $item['value'] = $value;
        break;
      case 'addr':
        $value = isset($_POST[$name]) ? trim($_POST[$name]) : '';
        $item['value'] = $value;
        break;
      default:
        $value = isset($_POST[$name]) ? trim($_POST[$name]) : '';
        $item['value'] = $value;
        break;
    }
  }
  return $items;
}

/**
 * Print HTML input elements corresponding to the given form item.
 * 
 * @param string $name
 * @param array $item
 */
function print_form_input($name, $item, $type = 'text') {
  $value = htmlentities($item['value'], ENT_QUOTES);
  $attributes = isset($item['attributes']) ? html_attributes($item['attributes']) : [];
  echo "<input type='{$type}' id='{$name}' name='{$name}' value='{$value}' {$attributes} required>";
}


function print_form_tel($name, $item, $type = 'tel') {
  $value = htmlentities($item['value'], ENT_QUOTES);
  #$attributes = isset($item['attributes']) ? html_attributes($item['attributes']) : [];
  echo "<input type='{$type}' id='{$name}' name='{$name}' value='{$value}' data-filter='\+[0-9\s]*' pattern='[0-9\-\+\s\(\)\/]*' placeholder='+591-0000-000000' required>";
  echo "<small>Bitte mit Ländercode eingeben.</small>";
  echo "<small><i>Por favor ingresa el código del país.</i></small>";
}

function print_form_tel2($name, $item, $type = 'tel2') {
  $value = htmlentities($item['value'], ENT_QUOTES);
  #$attributes = isset($item['attributes']) ? html_attributes($item['attributes']) : [];
  echo "<input type='{$type}' id='{$name}' name='{$name}' value='{$value}' data-filter='\+[0-9\s]*' pattern='[0-9\-\+\s\(\)\/]*' placeholder='+000-0000-000000' required>";
  echo "<small>Bitte mit Ländercode eingeben.</small>";
  echo "<small><i>Por favor ingresa el código del país.</i></small>";
}

function print_form_addr($name, $item, $type = 'addr') {
  $value = htmlentities($item['value'], ENT_QUOTES);
  $attributes = isset($item['attributes']) ? html_attributes($item['attributes']) : [];
  echo "<input type='{$type}' id='{$name}' name='{$name}' value='{$value}' {$attributes} required>";
  echo "<small>Die Adresse bitte in spanischer Sprache eingeben.</small>";
  echo "<small><i>Por favor ingresa la dirección en español.</i></small>";
}

function print_form_select($name, $item) {
  echo "<select name='{$name}' required>";
  echo "<option disabled selected hidden>Bitte wählen/Por favor seleccione...</option>";
  foreach ($item['choices'] as $choice) {
    $selected = $item['value'] === $choice ? 'selected' : '';
    $value = htmlentities($choice, ENT_QUOTES);
    echo "<option value='{$value}' {$selected}>{$value}</option>";
  }
  echo "</select>";
}


function print_form_multiselect($name, $item) {
  echo "<div class='choices'>";
  
  foreach ($item['choices'] as $index => $choice) {
    $checked = in_array($choice, $item['value']) ? 'checked' : '';
    $value = htmlentities($choice, ENT_QUOTES );
    echo "<label><input name='{$name}' type='checkbox' value='{$index}' {$checked}>{$value}</label>";
  }
  
  echo "</div>";
}


function print_form_textarea($name, $item) {
  $value = htmlentities($item['value'], ENT_QUOTES);
  $attributes = isset($item['attributes']) ? html_attributes($item['attributes']) : [];
  echo "<textarea name='{$name}' {$attributes} required>{$value}</textarea>";
  echo "<small class=chars></small>";
}

function print_form_textarea2($name, $item) {
  $value = htmlentities($item['value'], ENT_QUOTES);
  $attributes = isset($item['attributes']) ? html_attributes($item['attributes']) : [];
  echo "<textarea name='{$name}' {$attributes} required>{$value}</textarea>";
  echo "<small class=chars></small>";
}


function print_form_file($name, $item) {
  $extensions = htmlentities(implode(', ', $item['extensions']), ENT_QUOTES);
  $accept = htmlentities('.'.implode(', .', $item['extensions']), ENT_QUOTES);
  $attributes = isset($item['attributes']) ? html_attributes($item['attributes']) : [];
  $max_size = format_bytes(file_upload_max_size());
  echo "<input type='file' id='{$name}' name='{$name}' accept='{$accept}' {$attributes} required>";
  echo "<small>Erlaubte Dateiendungen: {$extensions}</small>";
  echo "<small>Maximale Dateigröße: {$max_size}</small>";
}


function print_form_date($name, $item) {
  $ranges = [
    'day' => range(1, 31),
    'month' => range(1, 12),
    'year' => range(1900, date('Y'))
  ];
  $labels = [
    'day' => 'Tag/Día...',
    'month' => 'Monat/Mes...',
    'year' => 'Jahr/Año...'
  ];
  echo "<div class='ranges'>";
  foreach ($ranges as $range_name => $range) {
    echo "<select name='{$name}[{$range_name}]' required>";
    $label = htmlentities($labels[$range_name], ENT_QUOTES);
    echo "<option disabled selected hidden>{$label}</option>";
    foreach ($range as $choice) {
      $selected = $item['value'][$range_name] == $choice ? 'selected' : '';
      $value = htmlentities($choice, ENT_QUOTES);
      echo "<option value='{$value}' {$selected}>{$value}</option>";
    }
    echo "</select>";
  }
  echo "</div>";
}


function print_form_item($name, $item, $class) {
  
  // Condition:
  if (isset($item['condition'])) {
    $condition_name = htmlentities($item['condition']['name'], ENT_QUOTES);
    $condition_values = htmlentities(json_encode($item['condition']['values']), ENT_QUOTES);
    $condition = "data-condition-name='{$condition_name}' data-condition-values='{$condition_values}'";
  } else {
    $condition = '';
  }
  
  $class = isset($item['class']) ? $item['class'].' '.$class : $class;
  
  echo "<a name='item-{$name}'></a>";
  echo "<div class='item item-{$item['type']} item-{$name} {$class}' {$condition}>";
  
  // Labels:
  if (isset($item['label'])) {
    foreach ($item['label'] as $label) {
      $label = htmlentities($label, ENT_QUOTES);
      echo "<label for='{$name}'>{$label}</label>";
    }
  }
  
  // Type:
  switch ($item['type']) {
    case 'select':
      print_form_select($name, $item);
      break;
    case 'multiselect':
      print_form_multiselect($name, $item);
      break;
    case 'textarea':
      print_form_textarea($name, $item);
      break;
    case 'textarea2':
      print_form_textarea2($name, $item);
      break;
    case 'file':
      print_form_file($name, $item);
      break;
    case 'date':
      print_form_date($name, $item);
      break;
    case 'tel':
      print_form_tel($name, $item);
      break;
    case 'tel2':
      print_form_tel2($name, $item);
      break;
    case 'addr':
      print_form_addr($name, $item);
      break;
    default:
      print_form_input($name, $item, $item['type']);
      break;
  }
  
   echo "</div>";
}

// All available form items are defined here:
$items = [
  // Application:
  'zeitraum' => [
    'type' => 'select',
    'name' => 'Zeitraum',
    'label' => ['Zeitraum', 'Periodo'],
    'choices' => ['Programa Colegio Andino Bogotá | 02.09.2019 - 02.02.2020 (15-16 años)','Programa S3-S4 Santa Cruz | 19.09.2019 - 02.01.2020 (15-16 años)','Programa S1-S2 Santa Cruz | 04.11.2019 - 23.12.2019 (13-14 años)','Programa Cochabamba | 04.11.2019 - 23.12.2019 (13-14 años)','Programa un año escolar Bogotá Colombia | (15-16 años)'],
    'validate' => 'validate_select'
  ],
  'anschreiben' => [
    'type' => 'textarea',
    'name' => 'Anschreiben',
    'label' => ['Anschreiben an Deine Gastfamilie', 'Carta de Presentación para tu familia anfitriona'],
    'validate' => 'validate_text',
    'attributes' => ['minlength' => 500, 'maxlength' => 800, 'rows' => 10, 
		     'placeholder' => 
		     "Hallo, mein Name ist Juan, ich bin * Jahre alt und ich wohne in *. Mein Vater heißt Roberto, er ist * Jahre alt, meine Mutter heißt Elisabet, sie ist * Jahre. Ich habe */*n Schwester/Bruder: Ana (* Jahre) und Pedro (* Jahre). In der Woche gehe ich zur Schule, dann esse ich mit meiner Familie zusammen, ich mache meine Hausaufgaben und drei Mal in der Woche fahre ich mit dem Fahrrad zum Fußballtraining. Die anderen Tage schwimme ich im Schwimmbad, spiele Gitarre oder spiele Playstation mit meinen Freunden. Am Wochenende bin ich bei meiner Familie oder treffe ich mich mit meinen Freunden und am Sontag gehe ich zur Kirche. Liebe Grüße Juan"
		     ],
    'class' => 'item-wide',
  ],
  'austausch' => [
    'type' => 'textarea',
    'name' => 'Vorstellung von Austausch',
    'label' => ['Wie stellst Du Dir Deinen Schüleraustausch in Deutschland vor?', '¿Cómo te imaginas el intercambio escolar en Alemania?'],
    'validate' => 'validate_text',
    'attributes' => ['minlength' => 100, 'maxlength' => 200,'rows' => 5],
    'class' => 'item-wide',
  ],
  'gastfamilie_vorstellung' => [
    'type' => 'textarea',
    'name' => 'Vorstellung von Gastfamilie',
    'label' => ['Wie stellst Du Dir Deine deutsche Gastfamilie vor?', '¿Cómo imaginas tu familia anfitriona en Alemania?'],
    'validate' => 'validate_text',
    'attributes' => ['minlength' => 100, 'maxlength' => 200, 'rows' => 5],
    'class' => 'item-wide',
  ],
  'entscheidung' => [
    'type' => 'textarea',
    'name' => 'Gefallen an Amigos de la Cultura',
    'label' => ['Was gefällt Dir am Schüleraustauschprogramm von Amigos de la Cultura?', '¿Qué te gusta del programa de intercambio de Amigos de la Cultura?'],
    'validate' => 'validate_text',
    'attributes' => ['minlength' => 100, 'maxlength' => 200, 'rows' => 5],
    'class' => 'item-wide',
  ],

  // Student:
  'geschlecht' => [
    'type' => 'select',
    'name' => 'Geschlecht',
    'label' => ['Geschlecht', 'Sexo'],
    'choices' => ['Männlich', 'Weiblich'],
    'validate' => 'validate_select',
  ],
  'vorname' => [
    'type' => 'text',
    'name' => 'Vorname',
    'label' => ['Vorname', 'Nombre'],
    'validate' => 'validate_text',
  ],
  'nachname' => [
    'type' => 'text',
    'name' => 'Nachname',
    'label' => ['Nachname', 'Apellidos'],
    'validate' => 'validate_text',
  ],
  'geburtsdatum' => [
    'type' => 'date',
    'name' => 'Geburtsdatum',
    'label' => ['Geburtsdatum', 'Fecha de Nacimiento'],
    'validate' => 'validate_date',
  ],
  'nationalität' => [
    'type' => 'text',
    'name' => 'Nationalität',
    'label' => ['Nationalität', 'Nacionalidad'],
    'validate' => 'validate_text',
  ],
  'passnummer' => [
    'type' => 'text',
    'name' => 'Passnummer',
    'label' => ['Passnummer', 'Nr. de Pasaporte'],
    'validate' => 'validate_text',
  ],
  'festnetznummer' => [
    'type' => 'tel',
    'name' => 'Festnetznummer',
    'label' => ['Festnetznummer', 'Teléfono de Casa'],
    'validate' => 'validate_tel',
    #'attributes' => ['pattern' => '[0-9\-\+\s\(\)\/]*'],
  ],
  'mobilfunknummer' => [
    'type' => 'tel',
    'name' => 'Mobilfunknummer',
    'label' => ['Mobilfunknummer', 'Teléfono de Celular'],
    'validate' => 'validate_tel',
    #'attributes' => ['pattern' => '[0-9\-\+\s\(\)\/]*'],
  ],
  'straße_hausnummer' => [
    'type' => 'addr',
    'name' => 'Straße, Hausnummer',
    'label' => ['Straße, Hausnummer', 'Calle, Nr. de Casa'],
    'validate' => 'validate_text',
  ],
  'stadt' => [
    'type' => 'addr',
    'name' => 'Stadt',
    'label' => ['Stadt', 'Ciudad'],
    'validate' => 'validate_text',
  ],
  'land' => [
    'type' => 'addr',
    'name' => 'Land',
    'label' => ['Land', 'País'],
    'validate' => 'validate_text',
  ],
  'email' => [
    'type' => 'email',
    'name' => 'E-Mail',
    'label' => ['E-Mail', 'Correo Electrónico'],
    'validate' => 'validate_email',
  ],
  'konfession' => [
    'type' => 'text',
    'name' => 'Religion',
    'label' => ['Religion', 'Religión'],
    'validate' => 'validate_text'
    #'choices' => ['Evangelisch', 'Katholisch', 'Andere', 'Keine'],
    #'validate' => 'validate_select'
  ],
  'kirchakt' => [
    'type' => 'text',
    'name' => 'Kirchliche Aktivitäten',
    'label' => ['Kirchliche Aktivitäten', 'Actividades en la Iglesia'],
    'validate' => 'validate_text'
    #'choices' => ['Evangelisch', 'Katholisch', 'Andere', 'Keine'],
    #'validate' => 'validate_select'
  ],
  'bekannte' => [
    'type' => 'select',
    'name' => 'Bekannte',
    'label' => ['Hast Du Verwandte oder Bekannte in Deutschland oder Europa?', '¿Tienes parientes o conocidos en Alemania o Europa?'],
    'choices' => ['Ja', 'Nein'],
    'validate' => 'validate_select',
    'class' => 'item-wide',
  ],
  'vorname_bekannte' => [
    'type' => 'text',
    'name' => 'Vorname (Verwandte oder Bekannte)',
    'label' => ['Vorname (Verwandte oder Bekannte)', 'Nombre (parientes o conocidos)'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'bekannte', 'values' => ['Ja']],
  ],
  'nachname_bekannte' => [
    'type' => 'text',
    'name' => 'Nachname (Verwandte oder Bekannte)',
    'label' => ['Nachname (Verwandte oder Bekannte)', 'Apellidos (parientes o conocidos)'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'bekannte', 'values' => ['Ja']],
  ],
  'festnetznummer_bekannte' => [
    'type' => 'tel2',
    'name' => 'Festnetznummer (Verwandte oder Bekannte)',
    'label' => ['Festnetznummer (Verwandte oder Bekannte)', 'Teléfono de Casa (parientes o conocidos)'],
    'validate' => 'validate_tel2',
    #'attributes' => ['pattern' => '[0-9\-\+\s\(\)\/]*'],
    'condition' => ['name' => 'bekannte', 'values' => ['Ja']],
  ],
  'mobilfunknummer_bekannte' => [
    'type' => 'tel2',
    'name' => 'Mobilfunknummer (Verwandte oder Bekannte)',
    'label' => ['Mobilfunknummer (Verwandte oder Bekannte)', 'Teléfono de Celular (parientes o conocidos)'],
    'validate' => 'validate_tel2',
    #'attributes' => ['pattern' => '[0-9\-\+\s\(\)\/]*'],
    'condition' => ['name' => 'bekannte', 'values' => ['Ja']],
  ],
  'straße_hausnummer_bekannte' => [
    'type' => 'text',
    'name' => 'Straße, Hausnummer (Verwandte oder Bekannte)',
    'label' => ['Straße, Hausnummer (Verwandte oder Bekannte)', 'Calle, Nr. de Casa (parientes o conocidos)'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'bekannte', 'values' => ['Ja']],
  ],
  'wohnort_bekannte' => [
    'type' => 'text',
    'name' => 'Postleitzahl, Wohnort (Verwandte oder Bekannte)',
    'label' => ['Postleitzahl, Wohnort (Verwandte oder Bekannte)', 'Código postal, lugar de residencia (parientes o conocidos)'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'bekannte', 'values' => ['Ja']],
  ],
  'land_bekannte' => [
    'type' => 'text',
    'name' => 'Land (Verwandte oder Bekannte)',
    'label' => ['Land (Verwandte oder Bekannte)', 'País (parientes o conocidos)'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'bekannte', 'values' => ['Ja']],
  ],
  'email_bekannte' => [
    'type' => 'email',
    'name' => 'E-Mail (Verwandte oder Bekannte)',
    'label' => ['E-Mail (Verwandte oder Bekannte)', 'Correo Electrónico (parientes o conocidos)'],
    'validate' => 'validate_email',
    'condition' => ['name' => 'bekannte', 'values' => ['Ja']],
  ],
  'vegetarier' => [
    'type' => 'select',
    'name' => 'Vegetarier/in',
    'label' => ['Bist Du Vegetarier/in?', '¿Eres vegetariano/a?'],
    'choices' => ['Ja', 'Nein'],
    'validate' => 'validate_select'
  ],
  'haustiere' => [
    'type' => 'select',
    'name' => 'Haustierbesitzer/in',
    'label' => ['Hast Du Haustiere?', '¿Tienes animales en casa?'],
    'choices' => ['Ja', 'Nein'],
    'validate' => 'validate_select'
  ],
  'welche_haustiere' => [
    'type' => 'text',
    'name' => 'Haustiere',
    'label' => ['Welche Haustiere?', '¿Cuáles?'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'haustiere', 'values' => ['Ja']],
    'class' => 'item-wide',
  ],
  'schwimmen' => [
    'type' => 'select',
    'name' => 'Kann Schwimmen',
    'label' => ['Kannst Du schwimmen?', '¿Puedes nadar?'],
    'choices' => ['Ja', 'Nein'],
    'validate' => 'validate_select',
  ],
  'sport' => [
    'type' => 'select',
    'name' => 'Macht Sport',
    'label' => ['Machst Du Sport?', '¿Haces deporte?'],
    'choices' => ['Ja', 'Nein'],
    'validate' => 'validate_select',
  ],
  'welche_sportarten' => [
    'type' => 'text',
    'name' => 'Sportarten',
    'label' => ['Welche Sportarten?', '¿Cuáles?'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'sport', 'values' => ['Ja']],
    'class' => 'item-wide',
  ],
  'instrument' => [
    'type' => 'select',
    'name' => 'Spielt ein Instrument',
    'label' => ['Spielst Du ein Instrument?', '¿Tocas algún instrumento musical?'],
    'choices' => ['Ja', 'Nein'],
    'validate' => 'validate_select',
  ],
  'welches_instrument' => [
    'type' => 'text',
    'name' => 'Instrumente',
    'label' => ['Welches Instrument?', '¿Cuáles?'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'instrument', 'values' => ['Ja']],
    'class' => 'item-wide',
  ],
  'berufswunsch' => [
    'type' => 'text',
    'name' => 'Berufswunsch',
    'label' => ['Welcher Beruf​ passt zu dir?', '¿Con cual carrera profesional ​te identificas?'],
    'validate' => 'validate_text',
  ],
  'verantwortlichkeiten_zuhause' => [
    'type' => 'textarea',
    'name' => 'Verantwortlichkeiten zu Hause',
    'label' => ['Welche Aufgaben und Verantwortlichkeiten hast Du zu Hause?', '¿Cuál son tus tareas y responsabilidades en la casa?'],
    'validate' => 'validate_text',
    'attributes' => ['minlength' => 100, 'maxlength' => 200, 'rows' => 5],
    'class' => 'item-wide',
  ],
  'smartphone' => [
    'type' => 'select',
    'name' => 'Tägliche Smartphonenutzung',
    'label' => ['Wie lange benutzt Du Dein Smartphone am Tag?', '¿Cuánto tiempo utilizas tu Smartphone durante el día?'],
    'choices' => ['0-2 h', '2-4 h', '4-6 h', '6-8 h', '8-10 h'],
    'validate' => 'validate_select',
  ],
  'selbstbeschreibung' => [
    'type' => 'text',
    'name' => 'Drei Wörter die mich beschreiben',
    'label' => ['Beschreibe Dich in drei Wörtern.', 'Describe tu personalidad con tres palabras.'],
    'validate' => 'validate_text',
  ],
  'freundbeschreibung' => [
    'type' => 'text',
    'name' => 'Meine Freunde beschreiben mich als',
    'label' => ['Wie beschreiben Dich Deine Freunde?', '¿Como te describen tus amigos?'],
    'validate' => 'validate_text',
  ],
  'hobbys' => [
    'type' => 'textarea',
    'name' => 'Freizeit und Hobbys',
    'label' => ['Freizeit und Hobbys', 'Tiempo libre y Hobbys', 'Erzähle über deine Hobbys und Interessen: Was sind deine Hobbys und Interessen? Seit wann machst du das Hobby? Wie oft übst du aktuell dein Hobby in der Woche aus?', 'Cuenta sobre tus Hobbys e Intereses: Cuáles son tus hobbys e intereses? Desde cuando practicas tus hobbys? Actualmente cuantas veces a la semana practicas tus hobbys?'],
    'validate' => 'validate_text',
    'attributes' => ['minlength' => 100, 'maxlength' => 200, 'rows' => 5],
    'class' => 'item-wide',
  ],
  
  'hobbyfoto' => [
    'type' => 'file',
    'name' => 'Hobbyfoto',
    'label' => ['Foto von Dir bei Deinem Lieblingshobby', 'Foto de ti con tu hobby favorito', 'No mayor a 3 meses.', 'Ojos no cubiertos por gafas de sol.', 'No screenshots de celular.'],
    'validate' => 'validate_file',
    'extensions' => ['gif', 'jpeg', 'jpg', 'png'],
  ],
    
  'internat' => [
    'type' => 'select',
    'name' => 'Bereitschaft für Internat',
    'label' => ['Wäre ein Internat eine Option für Dich?', '¿Sería un internado para tí una opción?'],
    'choices' => ['Ja', 'Nein'],
    'validate' => 'validate_select',
    #'class' => 'item-wide',
  ],
  'geschwister' => [
    'type' => 'select',
    'name' => 'Geschwister',
    'label' => ['Hast Du Geschwister?', '¿Tienes hermanos?'],
    'choices' => ['Nein', '1', '2', '3', '4', '5'],
    'validate' => 'validate_select',
    'class' => 'item-wide',
  ],
  'name_geschwister_1' => [
    'type' => 'text',
    'name' => 'Name (Geschwister 1)',
    'label' => ['Name (Geschwister 1)', 'Nombre (hermanos 1)'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'geschwister', 'values' => ['1', '2', '3', '4', '5']],
  ],
  'geburtsdatum_geschwister_1' => [
    'type' => 'date',
    'name' => 'Geburtsdatum (Geschwister 1)',
    'label' => ['Geburtsdatum (Geschwister 1)', 'Fecha de Nacimiento (hermanos 1)'],
    'validate' => 'validate_date',
    'condition' => ['name' => 'geschwister', 'values' => ['1', '2', '3', '4', '5']],
  ],
  'beruf_geschwister_1' => [
    'type' => 'text',
    'name' => 'Tätigkeit (Geschwister 1)',
    'label' => ['Beruf/Tätigkeit (Geschwister 1)', 'Profesión/Ocupación (hermanos 1)'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'geschwister', 'values' => ['1', '2', '3', '4', '5']],
    'class' => 'item-wide',
  ],
  'name_geschwister_2' => [
    'type' => 'text',
    'name' => 'Name (Geschwister 2)',
    'label' => ['Name (Geschwister 2)', 'Nombre (hermanos 2)'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'geschwister', 'values' => ['2', '3', '4', '5']],
  ],
  'geburtsdatum_geschwister_2' => [
    'type' => 'date',
    'name' => 'Geburtsdatum (Geschwister 2)',
    'label' => ['Geburtsdatum (Geschwister 2)', 'Fecha de Nacimiento (hermanos 2)'],
    'validate' => 'validate_date',
    'condition' => ['name' => 'geschwister', 'values' => ['2', '3', '4', '5']],
  ],
  'beruf_geschwister_2' => [
    'type' => 'text',
    'name' => 'Tätigkeit (Geschwister 2)',
    'label' => ['Beruf/Tätigkeit (Geschwister 2)', 'Profesión/Ocupación (hermanos 2)'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'geschwister', 'values' => ['2', '3', '4', '5']],
    'class' => 'item-wide',
  ],
  'name_geschwister_3' => [
    'type' => 'text',
    'name' => 'Name (Geschwister 3)',
    'label' => ['Name (Geschwister 3)', 'Nombre (hermanos 3)'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'geschwister', 'values' => ['3', '4', '5']],
  ],
  'geburtsdatum_geschwister_3' => [
    'type' => 'date',
    'name' => 'Geburtsdatum (Geschwister 3)',
    'label' => ['Geburtsdatum (Geschwister 3)', 'Fecha de Nacimiento (hermanos 3)'],
    'validate' => 'validate_date',
    'condition' => ['name' => 'geschwister', 'values' => ['3', '4', '5']],
  ],
  'beruf_geschwister_3' => [
    'type' => 'text',
    'name' => 'Tätigkeit (Geschwister 3)',
    'label' => ['Beruf/Tätigkeit (Geschwister 3)', 'Profesión/Ocupación (hermanos 3)'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'geschwister', 'values' => ['3', '4', '5']],
    'class' => 'item-wide',
  ],
  'name_geschwister_4' => [
    'type' => 'text',
    'name' => 'Name (Geschwister 4)',
    'label' => ['Name (Geschwister 4)', 'Nombre (hermanos 4)'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'geschwister', 'values' => ['4', '5']],
  ],
  'geburtsdatum_geschwister_4' => [
    'type' => 'date',
    'name' => 'Geburtsdatum (Geschwister 4)',
    'label' => ['Geburtsdatum (Geschwister 4)', 'Fecha de Nacimiento (hermanos 4)'],
    'validate' => 'validate_date',
    'condition' => ['name' => 'geschwister', 'values' => ['4', '5']],
  ],
  'beruf_geschwister_4' => [
    'type' => 'text',
    'name' => 'Tätigkeit (Geschwister 4)',
    'label' => ['Beruf/Tätigkeit (Geschwister 4)', 'Profesión/Ocupación (hermanos 4)'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'geschwister', 'values' => ['4', '5']],
    'class' => 'item-wide',
  ],
  'name_geschwister_5' => [
    'type' => 'text',
    'name' => 'Name (Geschwister 5)',
    'label' => ['Name (Geschwister 5)', 'Nombre (hermanos 5)'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'geschwister', 'values' => ['5']],
  ],
  'geburtsdatum_geschwister_5' => [
    'type' => 'date',
    'name' => 'Geburtsdatum (Geschwister 5)',
    'label' => ['Geburtsdatum (Geschwister 5)', 'Fecha de Nacimiento (hermanos 5)'],
    'validate' => 'validate_date',
    'condition' => ['name' => 'geschwister', 'values' => ['5']],
  ],
  'beruf_geschwister_5' => [
    'type' => 'text',
    'name' => 'Tätigkeit (Geschwister 5)',
    'label' => ['Beruf/Tätigkeit (Geschwister 5)', 'Profesión/Ocupación (hermanos 5)'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'geschwister', 'values' => ['5']],
    'class' => 'item-wide',
  ],
  'familienakt' => [
    'type' => 'textarea',
    'name' => 'Familienaktivitäten',
    'label' => ['Aktivitäten mit Deiner Familie?', '¿Qué actividades realizas con tu familia?'],
    'validate' => 'validate_text',
    'attributes' => ['minlength' => 100, 'maxlength' => 200, 'rows' => 5],
    'class' => 'item-wide',
  ],
  'sonstiges' => [
    'type' => 'textarea',
    'name' => 'Sonstiges',
    'label' => ['Sonstige Informationen, die wichtig für Deinen Schüleraustausch sind:', 'Otra información que es de importancia para tu intercambio escolar en Alemania:'],
    'validate' => 'validate_textarea2',
    'attributes' => ['minlength' => 0,'maxlength' => 200, 'rows' => 5], /*not required*/
    'class' => 'item-wide',
  ],

  'bewerbungsfoto' => [
    'type' => 'file',
    'name' => 'Bewerbungsfoto',
    'label' => ['Aktuelles Bewerbungsfoto', 'Foto actual del Solicitante', 'No mayor a 3 meses.', 'Ojos no cubiertos por gafas de sol.', 'No screenshots de celular.'],
    'validate' => 'validate_file',
    'extensions' => ['gif', 'jpeg', 'jpg', 'png'],
  ],

  'familienfoto' => [
    'type' => 'file',
    'name' => 'Familienfoto',
    'label' => ['Familienfoto', 'Foto Familiar', 'No mayor a 3 meses.', 'Ojos no cubiertos por gafas de sol.', 'No screenshots de celular.'],
    'validate' => 'validate_file',
    'extensions' => ['gif', 'jpeg', 'jpg', 'png'],
  ],
  
  'klasse' => [
    'type' => 'select',
    'name' => 'Klassenstufe',
    'label' => ['In welche Klasse gehst Du?', '¿En qué clase estás?'],
    'choices' => ['7.', '8.', '9.', '10.'],    
    'validate' => 'validate_text',
  ],
  'lieblingsfächer' => [
    'type' => 'text',
    'name' => 'Lieblingsfächer',
    'label' => ['Lieblingsfächer', 'Materias Favoritas'],
    'validate' => 'validate_text',
    #'choices' => ['Mathe', 'Physik', 'Chemie', 'Biologie', 'Geschichte', 'Geographie', 'Politik', 'Kunst', 'Musik', 'Theater', 'Sport', 'Deutsch', 'Englisch', 'Literatur', 'Informatik'],
    #'class' => 'item-wide',
  ],
  'wenigermagfächer' => [
    'type' => 'text',
    'name' => 'Nicht gemochte Fächer',
    'label' => ['Schulfächer, die ich weniger mag', 'Materias que me gustan poco'],
    'validate' => 'validate_text',
    #'choices' => ['Mathe', 'Physik', 'Chemie', 'Biologie', 'Geschichte', 'Geographie', 'Politik', 'Kunst', 'Musik', 'Theater', 'Sport', 'Deutsch', 'Englisch', 'Literatur', 'Informatik'],    
    #'class' => 'item-wide',
  ],
  'deutsch_wann' => [
    'type' => 'select',
    'name' => 'Lernt Deutsch seit',
    'label' => ['Seit wann lernst Du Deutsch?', '¿Hace cuánto tiempo estudias alemán?'],
    'choices' => ['1 Jahr', '2 Jahren', '3 Jahren', '4 Jahren', '5 Jahren', '6 Jahren', '7 Jahren', '8 Jahren', '9 Jahren', '10 Jahren'],    
    'validate' => 'validate_text',
  ],
  'deutsch_note' => [
    'type' => 'select',
    'name' => 'Letzte Deutschnote',
    'label' => ['Letzte Deutschnote', 'Última Nota en alemán'],
    'choices' => ['0-60', '61-65', '66-70', '71-75', '76-80', '81-85', '86-90', '91-95', '96-100'],  
    'validate' => 'validate_text'
  ],
  'deutsch_warum' => [
    'type' => 'textarea',
    'name' => 'Beweggrund für das Erlernen der deutschen Sprache',
    'label' => ['Was gefällt Dir an der deutschen Sprache?', '¿Qué te gusta del idioma alemán?'],
    'validate' => 'validate_text',
    'attributes' => ['minlength' => 100, 'maxlength' => 200, 'rows' => 5],
    'class' => 'item-wide',
  ],
  'verantwortlichkeiten_schule' => [
    'type' => 'textarea',
    'name' => 'Verantwortlichkeiten in der Schule',
    'label' => ['Welche Aufgaben und Verantwortlichkeiten hast Du in der Schule?', '¿Cuales son tus tareas y responsabilidades en el colegio?'],
    'validate' => 'validate_text',
    'attributes' => ['minlength' => 100, 'maxlength' => 200, 'rows' => 5],
    'class' => 'item-wide',
  ],
  'zeugnis' => [
    'type' => 'file',
    'name' => 'Zeugnis',
    'label' => ['Letztes Zeugnis', 'Última Libreta', 'El documento debe ser legible.'],
    'validate' => 'validate_file',
    'extensions' => ['gif', 'jpeg', 'jpg','pdf', 'png'],
  ],
  
  // Mother:
  'vorname_mutter' => [
    'type' => 'text',
    'name' => 'Vorname',
    'label' => ['Vorname', 'Nombre'],
    'validate' => 'validate_text',
  ],
  'nachname_mutter' => [
    'type' => 'text',
    'name' => 'Nachname',
    'label' => ['Nachname', 'Apellidos'],
    'validate' => 'validate_text',
  ],
  'geburtsdatum_mutter' => [
    'type' => 'date',
    'name' => 'Geburtsdatum',
    'label' => ['Geburtsdatum', 'Fecha de Nacimiento'],
    'validate' => 'validate_date',
  ],
  'nationalität_mutter' => [
    'type' => 'text',
    'name' => 'Nationalität',
    'label' => ['Nationalität', 'Nacionalidad'],
    'validate' => 'validate_text',
  ],
  'festnetznummer_mutter' => [
    'type' => 'tel',
    'name' => 'Festnetznummer',
    'label' => ['Festnetznummer', 'Teléfono de Casa'],
    'validate' => 'validate_tel',
    #'attributes' => ['pattern' => '[0-9\-\+\s\(\)\/]*'],
  ],
  'mobilfunknummer_mutter' => [
    'type' => 'tel',
    'name' => 'Mobilfunknummer',
    'label' => ['Mobilfunknummer', 'Teléfono de Celular'],
    'validate' => 'validate_tel',
    #'attributes' => ['pattern' => '[0-9\-\+\s\(\)\/]*'],
  ],
  'straße_hausnummer_mutter' => [
    'type' => 'text',
    'name' => 'Straße, Hausnummer',
    'label' => ['Straße, Hausnummer', 'Calle, Nr. de Casa'],
    'validate' => 'validate_text',
  ],
  'email_mutter' => [
    'type' => 'email',
    'name' => 'E-Mail',
    'label' => ['E-Mail', 'Correo Electrónico'],
    'validate' => 'validate_email',
  ],
  'beruf_mutter' => [
    'type' => 'text',
    'name' => 'Beruf',
    'label' => ['Beruf/Tätigkeit', 'Profesión/Ocupación'],
    'validate' => 'validate_text',
  ],
  'arbeitgeber_mutter' => [
    'type' => 'text',
    'name' => 'Arbeitgeber',
    'label' => ['Arbeitgeber', 'Empresa o lugar de trabajo'],
    'validate' => 'validate_text',
  ],

  // Father:
  'vorname_vater' => [
    'type' => 'text',
    'name' => 'Vorname',
    'label' => ['Vorname', 'Nombre'],
    'validate' => 'validate_text',
  ],
  'nachname_vater' => [
    'type' => 'text',
    'name' => 'Nachname',
    'label' => ['Nachname', 'Apellidos'],
    'validate' => 'validate_text',
  ],
  'geburtsdatum_vater' => [
    'type' => 'date',
    'name' => 'Geburtsdatum',
    'label' => ['Geburtsdatum', 'Fecha de Nacimiento'],
    'validate' => 'validate_date',
  ],
  'nationalität_vater' => [
    'type' => 'text',
    'name' => 'Nationalität',
    'label' => ['Nationalität', 'Nacionalidad'],
    'validate' => 'validate_text',
  ],
  'festnetznummer_vater' => [
    'type' => 'tel',
    'name' => 'Festnetznummer',
    'label' => ['Festnetznummer', 'Teléfono de Casa'],
    'validate' => 'validate_tel',
    #'attributes' => ['pattern' => '[0-9\-\+\s\(\)\/]*'],
  ],
  'mobilfunknummer_vater' => [
    'type' => 'tel',
    'name' => 'Mobilfunknummer',
    'label' => ['Mobilfunknummer', 'Teléfono de Celular'],
    'validate' => 'validate_tel',
    #'attributes' => ['pattern' => '[0-9\-\+\s\(\)\/]*'],
  ],
  'straße_hausnummer_vater' => [
    'type' => 'text',
    'name' => 'Straße, Hausnummer',
    'label' => ['Straße, Hausnummer', 'Calle, Nr. de Casa'],
    'validate' => 'validate_text',
  ],
  'email_vater' => [
    'type' => 'email',
    'name' => 'E-Mail',
    'label' => ['E-Mail', 'Correo Electrónico'],
    'validate' => 'validate_email',
  ],
  'beruf_vater' => [
    'type' => 'text',
    'name' => 'Beruf',
    'label' => ['Beruf/Tätigkeit', 'Profesión/Ocupación'],
    'validate' => 'validate_text',
  ],
  'arbeitgeber_vater' => [
    'type' => 'text',
    'name' => 'Arbeitgeber',
    'label' => ['Arbeitgeber', 'Empresa o lugar de trabajo'],
    'validate' => 'validate_text',
  ],

//Angaben zu Ihrer Tochter/Ihrem Sohn
  'allergien' => [
    'type' => 'select',
    'name' => 'Allergien / chronische Erkrankungen',
    'label' => ['Leidet Ihre Tochter/Ihr Sohn unter Allergien oder chronischen Erkrankungen?', '¿Padece su hija/hijo de alérgias o enfermedades crónicas?'],
    'choices' => ['Ja/Sí', 'Nein/No'],
    'validate' => 'validate_select',
    'class' => 'item-wide',
  ],
  'welche_allergien' => [
    'type' => 'text',
    'name' => 'Allergien oder chronische Erkrankungen',
    'label' => ['Unter welchen Allergien oder chronischen Erkrankungen?', '¿Bajo cuáles alergias o enfermedades crónicas?'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'allergien', 'values' => ['Ja/Sí']],
    'class' => 'item-wide',
  ],
  'medikamente' => [
    'type' => 'select',
    'name' => 'Regelmäßige Medikamenteneinnahme',
    'label' => ['Nimmt Ihre Tochter/Ihr Sohn Kind regelmäßig Medikamente nach ärztlicher Verordnung ein?', '¿Toma su hija/hijo medicamentos bajo prescripción médica?'],
    'choices' => ['Ja/Sí', 'Nein/No'],
    'validate' => 'validate_select',
    'class' => 'item-wide',
  ],
  'welche_medikamente' => [
    'type' => 'text',
    'name' => 'Medikamente',
    'label' => ['Welche Medikamente?', '¿Cuáles medicamentos?'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'medikamente', 'values' => ['Ja/Sí']],
    'class' => 'item-wide',
  ],
  'nahrung' => [
    'type' => 'select',
    'name' => 'Diät oder Lebensmittelunverträglichkeiten',
    'label' => ['Muss Ihre Tochter/Ihr Sohn eine spezielle Diät einhalten oder liegen Lebensmittelunverträglichkeiten vor?', '¿Debe seguir su hija/hijo alguna dieta especial o tiene intolerancias alimentarias?'],
    'choices' => ['Ja/Sí', 'Nein/No'],
    'validate' => 'validate_select',
    'class' => 'item-wide',
  ],
  'welche_nahrung' => [
    'type' => 'text',
    'name' => 'Diät oder Lebensmittelunverträglichkeiten',
    'label' => ['Welche Diät oder Lebensmittelunverträglichkeiten?', '¿Cuál dieta o intolerancias alimentarias?'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'nahrung', 'values' => ['Ja/Sí']],
    'class' => 'item-wide',
  ],
  'fahrrad' => [
    'type' => 'select',
    'name' => 'Kann Fahrradfahren',
    'label' => ['Kann Ihre Tochter/Ihr Sohn Fahrradfahren?', '¿Sabe andar su hija/hijo en bicicleta?'],
    'choices' => ['Ja/Sí', 'Nein/No'],
    'validate' => 'validate_select',
    'class' => 'item-wide',
  ],
  'fahrradnutzung' => [
    'type' => 'text',
    'name' => 'Häufigkeit der Fahrradnutzung',
    'label' => ['Wo und wie oft benutzt Ihre Tochter/Ihr Sohn das Fahrrad?', '¿Dónde y con qué frecuencia utiliza su hija/hijo la bicicleta?'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'fahrrad', 'values' => ['Ja/Sí']],
    'class' => 'item-wide',
  ],
  
  
  'gastfamilie' => [
    'type' => 'select',
    'name' => 'Gastfamilie',
    'label' => ['Hat Ihre Tochter/Ihr Sohn bereits eine Gastfamilie?', '¿Cuentan ustedes actualmente con una familia anfitriona?'],
    'choices' => ['Ja/Sí', 'Nein/No'],
    'validate' => 'validate_select',
    'class' => 'item-wide',
  ],
  'vorname_gastfamilie' => [
    'type' => 'text',
    'name' => 'Vorname (Gastfamilie)',
    'label' => ['Vorname (Gastfamilie)', 'Nombre (familia anfitriona)'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'gastfamilie', 'values' => ['Ja/Sí']],
  ],
  'nachname_gastfamilie' => [
    'type' => 'text',
    'name' => 'Nachname (Gastfamilie)',
    'label' => ['Nachname (Gastfamilie)', 'Apellidos (familia anfitriona)'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'gastfamilie', 'values' => ['Ja/Sí']],
  ],
  'festnetznummer_gastfamilie' => [
    'type' => 'tel2',
    'name' => 'Festnetznummer (Gastfamilie)',
    'label' => ['Festnetznummer (Gastfamilie)', 'Teléfono de Casa (familia anfitriona)'],
    'validate' => 'validate_tel2',
    #'attributes' => ['pattern' => '[0-9\-\+\s\(\)\/]*'],
    'condition' => ['name' => 'gastfamilie', 'values' => ['Ja/Sí']],
  ],
  'mobilfunknummer_gastfamilie' => [
    'type' => 'tel2',
    'name' => 'Mobilfunknummer (Gastfamilie)',
    'label' => ['Mobilfunknummer (Gastfamilie)', 'Teléfono de Celular (familia anfitriona)'],
    'validate' => 'validate_tel2',
    #'attributes' => ['pattern' => '[0-9\-\+\s\(\)\/]*'],
    'condition' => ['name' => 'gastfamilie', 'values' => ['Ja/Sí']],
  ],
  'straße_hausnummer_gastfamilie' => [
    'type' => 'text',
    'name' => 'Straße, Hausnummer (Gastfamilie)',
    'label' => ['Straße, Hausnummer (Gastfamilie)', 'Calle, Nr. de Casa (familia anfitriona)'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'gastfamilie', 'values' => ['Ja/Sí']],
  ],
  'wohnort_gastfamilie' => [
    'type' => 'text',
    'name' => 'Wohnort, Postleitzahl (Gastfamilie)',
    'label' => ['Postleitzahl, Wohnort (Gastfamilie)', ' Código postal, lugar de residencia (familia anfitriona)'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'gastfamilie', 'values' => ['Ja/Sí']],
  ],
  'land_gastfamilie' => [
    'type' => 'text',
    'name' => 'Land (Gastfamilie)',
    'label' => ['Land (Gastfamilie)', 'País (familia anfitriona)'],
    'validate' => 'validate_text',
    'condition' => ['name' => 'gastfamilie', 'values' => ['Ja/Sí']],
  ],
  'email_gastfamilie' => [
    'type' => 'email',
    'name' => 'E-Mail (Gastfamilie)',
    'label' => ['E-Mail (Gastfamilie)', 'Correo Electrónico (familia anfitriona)'],
    'validate' => 'validate_email',
    'condition' => ['name' => 'gastfamilie', 'values' => ['Ja/Sí']],
  ],
  'sonstiges2' => [
    'type' => 'textarea2',
    'name' => 'Sonstiges',
    'label' => ['Sonstige Informationen, die wichtig für den Schüleraustausch sind:', 'Otra información que es de importancia para el intercambio escolar en Alemania:'],
    'validate' => 'validate_textarea2',
    'attributes' => ['minlength' => 0, 'maxlength' => 200, 'rows' => 5], /*not required*/
    'class' => 'item-wide',
  ],
  /*
  'bestätigung' => [
    'type' => 'select',
    'name' => 'Richtigkeit der Angaben bestätigt',
    'label' => ['Nosotros, los padres y el solicitante confirmamos, que los datos del presente formulario son correctos. Ocultar información o declarar datos falsos sobre enfermedades graves anteriores y actuales serán interpretadas como un acto de engaño doloso contemplado en la justicia alemana pudiendo traer como consecuencia la inmediata interrupción del programa de intercambio a través de Amigos de la Cultura.
Doy fe de haber escrito los datos del formulario presente con la verdad y sin omisión alguna. Declaraciones falsas serán denunciadas ante la policía de migración alemana.'],
    'choices' => ['Ja/Sí'],
    'validate' => 'validate_select',
    'class' => 'item-wide',
  ],
*/

//checkbox ('Ja/Sí' ausgeben)

    'bestätigung' => [
    'type' => 'multiselect',
    'name' => 'Die Richtigkeit der Angaben wurde seitens der Familie bestätigt.',
    'label' => [''],
    'choices' => [' Nosotros, los padres y el solicitante confirmamos, que los datos del presente formulario son correctos. Ocultar información o declarar datos falsos sobre enfermedades graves anteriores y actuales serán interpretadas como un acto de engaño doloso contemplado en la justicia alemana pudiendo traer como consecuencia la inmediata interrupción del programa de intercambio a través de Amigos de la Cultura.
Doy fe de haber escrito los datos del formulario presente con la verdad y sin omisión alguna. Declaraciones falsas serán denunciadas ante la policía de migración alemana.'],
    'validate' => 'validate_multiselect',
    'class' => 'item-wide',
  ],


];

// Form items are grouped by name into fieldsets here:
$fieldsets = [
  'allgemein' => [
    'legend' => ['Anschreiben für eine Gastfamilie', 'Carta de Presentación para una familia anfitriona', '', '', 'Der erste Teil des Antragsformulars ist Deine Empfehlung für eine Gastfamilie. Die Gastfamilie bekommt einen Brief mit Deiner Bewerbung. Bitte mache vollständige, wahrheitsgemäße Angaben und keine "copy and paste" Angaben.', 'La primera parte del formulario de solicitud es una referencia para tu familia anfitriona. La familia anfitriona recibirá una carta con tu solicitud. Proporciona información completa y virídica, no copies y pegues información.'],
    'items' => [
      'zeitraum',
      'anschreiben',
      'austausch',
      'gastfamilie_vorstellung',
      'entscheidung',
    ],
  ], 
  'schüler' => [
    'legend' => ['Persönliche Daten', 'Datos Personales'],
    'items' => [
      'geschlecht',
      'vorname',
      'nachname',
      'geburtsdatum',
      'nationalität',
      'passnummer',
      'festnetznummer',
      'mobilfunknummer',
      'straße_hausnummer',
      'stadt',
      'land',
      'email',
      'konfession',
      'kirchakt',
      'bekannte',
      'vorname_bekannte',
      'nachname_bekannte',
      'festnetznummer_bekannte',
      'mobilfunknummer_bekannte',
      'straße_hausnummer_bekannte',
      'wohnort_bekannte',
      'land_bekannte',
      'email_bekannte',
      'vegetarier',
      'haustiere',
      'welche_haustiere',
      'schwimmen',
      'sport',
      'welche_sportarten',
      'instrument',
      'welches_instrument',
      'berufswunsch',
      'selbstbeschreibung',
      'verantwortlichkeiten_zuhause',
      'smartphone',
      'freundbeschreibung',
      'hobbys',
      'hobbyfoto',
      'internat',
      'geschwister',
      'name_geschwister_1',
      'geburtsdatum_geschwister_1',
      'beruf_geschwister_1',
      'name_geschwister_2',
      'geburtsdatum_geschwister_2',
      'beruf_geschwister_2',
      'name_geschwister_3',
      'geburtsdatum_geschwister_3',
      'beruf_geschwister_3',
      'name_geschwister_4',
      'geburtsdatum_geschwister_4',
      'beruf_geschwister_4',
      'name_geschwister_5',
      'geburtsdatum_geschwister_5',
      'beruf_geschwister_5',
      'familienakt',
      'sonstiges',
      'bewerbungsfoto',
      'familienfoto',
    ],
  ], 
  'schule' => [
    'legend' => ['Schule', 'Colegio'],
    'items' => [
      'klasse',
      'lieblingsfächer',
      'wenigermagfächer',
      'deutsch_wann',
      'deutsch_note',
      'deutsch_warum',
      'verantwortlichkeiten_schule',
      'zeugnis',
    ],
  ], 
  'mutter' => [
    'legend' => ['Angaben der Mutter', 'Datos de la mamá del solicitante', 'Dieser Teil ist nur durch die Eltern auszufüllen.', 'Esta parte debe ser llenada solamente por los padres.'],
    'items' => [
      'vorname_mutter',
      'nachname_mutter',
      'geburtsdatum_mutter',
      'nationalität_mutter',
      'festnetznummer_mutter',
      'mobilfunknummer_mutter',
      'straße_hausnummer_mutter',
      'email_mutter',
      'beruf_mutter',
      'arbeitgeber_mutter',
    ],
  ], 
  'vater' => [
    'legend' => ['Angaben des Vaters', 'Datos del papá del solicitante', 'Dieser Teil ist nur durch die Eltern auszufüllen.', 'Esta parte debe ser llenada solamente por los padres.'],
    'items' => [
      'vorname_vater',
      'nachname_vater',
      'geburtsdatum_vater',
      'nationalität_vater',
      'festnetznummer_vater',
      'mobilfunknummer_vater',
      'straße_hausnummer_vater',
      'email_vater',
      'beruf_vater',
      'arbeitgeber_vater',
    ],
  ],
  'gesundheit' => [
  'legend' => ['Angaben zu Ihrer Tochter/Ihrem Sohn', 'Datos de su hija/hijo', 'Dieser Teil ist nur durch die Eltern auszufüllen.', 'Esta parte debe ser llenada solamente por los padres.'],
  'items' => [
      'allergien',
      'welche_allergien',
      'medikamente',
      'welche_medikamente',
      'nahrung',
      'welche_nahrung',
      'fahrrad',
      'fahrradnutzung',
      'gastfamilie',
      'vorname_gastfamilie',
      'nachname_gastfamilie',
      'festnetznummer_gastfamilie',
      'mobilfunknummer_gastfamilie',
      'straße_hausnummer_gastfamilie',
      'wohnort_gastfamilie',
      'land_gastfamilie',
      'email_gastfamilie',
      'sonstiges2',
    ],
  ],
  'bestätigung' => [
    'legend' => ['Bestätigung', 'Confirmación', 'Dieser Teil ist nur durch die Eltern auszufüllen.', 'Esta parte debe ser llenada solamente por los padres.'],
    'items' => [
      'bestätigung',
    ],
  ],
];

$errors = [];
$success = false;

initialize_form_items($items);

if (isset($_POST['submit'])) {
  
  $valid = validate_form_items($fieldsets, $items);
  if ($valid) {
    try {
      send_registration_mail($fieldsets, $items);
      send_confirmation_student_mail($fieldsets, $items);
      send_confirmation_mother_mail($fieldsets, $items);
      send_confirmation_father_mail($fieldsets, $items);
      $success = true;
    } catch (Exception $e) {
      $errors[] = "Formular konnte nicht gesendet werden: {$e->getMessage()}";
    }
  } else {
    $errors[] = "No todos los campos están correctamente llenados.";
  }
  
} else if (isset($_POST['preview'])) {
  
  $valid = validate_form_items($fieldsets, $items);

  if ($valid) {
    try {
      create_pdf($fieldsets, $items, true);
    } catch (Exception $e) {
      $errors[] = "Vorschau konnte nicht erstellt werden: {$e->getMessage()}";
    }
  } else {
    $errors[] = "No todos los campos están correctamente llenados.";
  }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" type="text/css" href="resources/style.css">
  <title>Formulario de Solicitud - Intercambio escolar a Alemania</title>
</head>
<body>
  <div id="overlay-register" class="overlay hidden">
    <span class="icon spin">↻</span> Daten werden gesendet...
  </div> 
   
  <header>
    <img src="resources/logo.gif" width="166" height="166">
    <h1>Bewerbungsformular – Schüleraustausch mit Amigos de la Cultura<span>Formulario de Solicitud – Intercambio con Amigos de la Cultura</span></h1>
  </header>

  <main>
    <?php
    if ($success) {
      echo "<div class='msg success'>Bewerbungsformular abgeschickt. Formulario de solicitud enviado.</div>";
    } else {
    
      // display general error messages:
      foreach ($errors as $error) {
        echo "<div class='msg error'><strong>Fehler beim Senden des Formulares:</strong> {$error}</div>";
      }
      
      // display item validation error messages:
      foreach ($fieldsets as $fieldset) {
        foreach ($fieldset['items'] as $name) {
          $legend = htmlentities($fieldset['legend'][1]);
          if (isset($items[$name]['error'])) {
            $error = htmlentities($items[$name]['error']);
            $label = isset($items[$name]) ? htmlentities($items[$name]['label'][1]) : 'Error';
            echo "<a href='#item-{$name}'><div class='msg error'><strong>{$legend} → {$label}:</strong> {$error}</div></a>";
          }
        }
      }
      ?>
      
      
      <br><br><br><br>
      <p><strong>Hinweis:</strong> Das Formular muss der Teilnehmer gemeinsam mit seinen Eltern ausfüllen. Bitte in <u>DEUTSCHER</u> Sprache ausfüllen.</p>
      <p><i><strong>Nota:</strong> El presente formulario debe ser llenado por el solicitante (alumno/a) en compañia de sus padres. Por favor llenar en idioma <u>ALEMÁN</u>.</i></p>
      <br><br><br><br><br>
      
      
      <form id="form-register" method="post" enctype="multipart/form-data">
      

      <?php
        
        // print fieldsets:
        foreach ($fieldsets as $fieldset) {
          echo "<div class='fieldset'>";
          
          // print legend:
          echo "<div class='legend'>";
          foreach ($fieldset['legend'] as $legend) {
            $legend = htmlentities($legend);
            echo "<div>{$legend}</div>";
          }
          echo "</div>";
          
          // print items:
          foreach ($fieldset['items'] as $name) {
            $item = $items[$name];
            $error = isset($errors[$name]) ? 'error' : '';
            print_form_item($name, $item, $error);
          }
          
          echo "</div>";
        }
        ?>
        
        <div class="item item-submit">
          <input id="preview" type="submit" name="preview" value="Vorschau/Vista previa">
          <input id="submit" type="submit" name="submit" value="Absenden/Enviar">
        </div>
      </form>
      <?php
    }
    ?>
  </main>
  <footer>
    <ul>
      <li>Copyright © 2017 Amigos de la Cultura e.V.</li>
      <li><a href="http://amigos-cultura.de/datenschutz">Datenschutzerklärung</a> <a href="http://amigos-cultura.de/impressum">Impressum</a></li>
    </ul>
  </footer>
  <script>
    
    // Display overlay during form submission:
    const overlay = document.getElementById("overlay-register");
    const form = document.getElementById("form-register");
    
    form.addEventListener("submit", (event) => {
      if (!form.target) overlay.classList.toggle("hidden");
    });

    const preview = document.getElementById("preview");
    preview.addEventListener("click", (event) => {
      form.target = "_blank";
    });
    
    const submit = document.getElementById("submit");
    submit.addEventListener("click", (event) => {
      form.target = "";
    });
    
    // Handle conditional items:
    const inputs = document.querySelectorAll("select, input, textarea");
    
    inputs.forEach((input) => {
      const conditionals = document.querySelectorAll(".item[data-condition-name='" + input.name + "']");

      conditionals.forEach((conditional) => {
        input.addEventListener("change", (event) => {
          const values = JSON.parse(conditional.dataset.conditionValues);
          const conditional_inputs = conditional.querySelectorAll("select, input, textarea");
          
          if (!values.includes(input.value)) {
            conditional.classList.add("hidden");
            conditional_inputs.forEach((conditional_input) => {
              conditional_input.disabled = true;
            });
          } else {
            conditional.classList.remove("hidden");
            conditional_inputs.forEach((conditional_input) => {
              conditional_input.disabled = false;
            });
          }
        });
      });
      
      input.dispatchEvent(new Event("change"));
    });

	//Apply textarea counter
	
	var textareasArray = document.querySelectorAll("textarea");

	textareasArray.forEach(function(textarea) {

		textarea.addEventListener("input", function(){
			var minLength = this.getAttribute("minlength");
			var maxLength = this.getAttribute("maxlength");
			var currentLength = this.value.length;
			
			var requiredLength = minLength - currentLength;
			var remainingLength = maxLength - currentLength;
			
			var chars = textarea.parentElement.querySelector(".chars");

			if ( currentLength < minLength )
				{
				chars.innerHTML = (requiredLength + " characters required");
				console.log(requiredLength + " characters remaining");
				}
			else if ( currentLength < maxLength )
				{
				chars.innerHTML = (remainingLength + " characters remaining");
				console.log(remainingLength + " characters remaining");
				}
			else
				{
				chars.innerHTML = (" characters exhausted");
				console.log("tippeltippel");
				}
		});
	});

    // Apply filter to all inputs with data-filter:
    let telinputs = document.querySelectorAll('input[data-filter]');

    for (let input of telinputs) {
      let state = {
        value: input.value,
        start: input.selectionStart,
        end: input.selectionEnd,
        pattern: RegExp('^' + input.dataset.filter + '$')
      };
      
      input.addEventListener('input', event => {
        if (state.pattern.test(input.value)) {
          state.value = input.value;
        } else {
          input.value = state.value;
          input.setSelectionRange(state.start, state.end);
        }
      });

      input.addEventListener('keydown', event => {
        state.start = input.selectionStart;
        state.end = input.selectionEnd;
      });
    }

  </script>
</body>
</html>
