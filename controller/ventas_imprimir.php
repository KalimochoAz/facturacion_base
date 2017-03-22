<?php
/*
 * This file is part of facturacion_base
 * Copyright (C) 2014-2017  Carlos Garcia Gomez  neorazorx@gmail.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require_once __DIR__.'/../extras/fs_pdf.php';
require_once 'extras/phpmailer/class.phpmailer.php';
require_once 'extras/phpmailer/class.smtp.php';
require_model('articulo_traza.php');
require_model('cliente.php');
require_model('cuenta_banco.php');
require_model('cuenta_banco_cliente.php');
require_model('forma_pago.php');

/**
 * Esta clase agrupa los procedimientos de imprimir/enviar albaranes y facturas.
 */
class ventas_imprimir extends fs_controller
{
   public $cliente;
   public $documento;
   public $impresion;
   public $impuesto;
   
   private $articulo_traza;
   private $numpaginas;
   
   public function __construct()
   {
      parent::__construct(__CLASS__, 'imprimir', 'ventas', FALSE, FALSE);
   }
   
   protected function private_core()
   {
      $this->cliente = FALSE;
      $this->documento = FALSE;
      $this->impuesto = new impuesto();
      
      /// obtenemos los datos de configuración de impresión
      $this->impresion = array(
          'print_ref' => '1',
          'print_dto' => '1',
          'print_alb' => '0',
          'print_formapago' => '1'
      );
      $fsvar = new fs_var();
      $this->impresion = $fsvar->array_get($this->impresion, FALSE);
      
      if( isset($_REQUEST['albaran']) AND isset($_REQUEST['id']) )
      {
         $this->articulo_traza = new articulo_traza();
         
         $alb = new albaran_cliente();
         $this->documento = $alb->get($_REQUEST['id']);
         if($this->documento)
         {
            $cliente = new cliente();
            $this->cliente = $cliente->get($this->documento->codcliente);
         }
         
         if( isset($_POST['email']) )
         {
            $this->enviar_email('albaran');
         }
         else
            $this->generar_pdf_albaran();
      }
      else if( isset($_REQUEST['factura']) AND isset($_REQUEST['id']) )
      {
         $this->articulo_traza = new articulo_traza();
         
         $fac = new factura_cliente();
         $this->documento = $fac->get($_REQUEST['id']);
         if($this->documento)
         {
            $cliente = new cliente();
            $this->cliente = $cliente->get($this->documento->codcliente);
         }
         
         if( isset($_POST['email']) )
         {
            $this->enviar_email('factura', $_REQUEST['tipo']);
         }
         else
            $this->generar_pdf_factura($_REQUEST['tipo']);
      }
      
      $this->share_extensions();
   }
   
   private function share_extensions()
   {
      $extensiones = array(
          array(
              'name' => 'imprimir_albaran',
              'page_from' => __CLASS__,
              'page_to' => 'ventas_albaran',
              'type' => 'pdf',
              'text' => '<span class="glyphicon glyphicon-print"></span>&nbsp; '.ucfirst(FS_ALBARAN).' simple',
              'params' => '&albaran=TRUE'
          ),
          array(
              'name' => 'imprimir_albaran_noval',
              'page_from' => __CLASS__,
              'page_to' => 'ventas_albaran',
              'type' => 'pdf',
              'text' => '<span class="glyphicon glyphicon-print"></span>&nbsp; '.ucfirst(FS_ALBARAN).' sin valorar',
              'params' => '&albaran=TRUE&noval=TRUE'
          ),
          array(
              'name' => 'email_albaran',
              'page_from' => __CLASS__,
              'page_to' => 'ventas_albaran',
              'type' => 'email',
              'text' => ucfirst(FS_ALBARAN).' simple',
              'params' => '&albaran=TRUE'
          ),
          array(
              'name' => 'imprimir_factura',
              'page_from' => __CLASS__,
              'page_to' => 'ventas_factura',
              'type' => 'pdf',
              'text' => '<span class="glyphicon glyphicon-print"></span>&nbsp; '.ucfirst(FS_FACTURA).' simple',
              'params' => '&factura=TRUE&tipo=simple'
          ),
          array(
              'name' => 'imprimir_factura_carta',
              'page_from' => __CLASS__,
              'page_to' => 'ventas_factura',
              'type' => 'pdf',
              'text' => '<span class="glyphicon glyphicon-print"></span>&nbsp; Modelo carta',
              'params' => '&factura=TRUE&tipo=carta'
          ),
          array(
              'name' => 'email_factura',
              'page_from' => __CLASS__,
              'page_to' => 'ventas_factura',
              'type' => 'email',
              'text' => ucfirst(FS_FACTURA).' simple',
              'params' => '&factura=TRUE&tipo=simple'
          )
      );
      foreach($extensiones as $ext)
      {
         $fsext = new fs_extension($ext);
         if( !$fsext->save() )
         {
            $this->new_error_msg('Error al guardar la extensión '.$ext['name']);
         }
      }
   }
   
   private function generar_pdf_lineas(&$pdf_doc, &$lineas, &$linea_actual, &$lppag)
   {
      /// calculamos el número de páginas
      if( !isset($this->numpaginas) )
      {
         $this->numpaginas = 0;
         $linea_a = 0;
         while( $linea_a < count($lineas) )
         {
            $lppag2 = $lppag;
            foreach($lineas as $i => $lin)
            {
               if($i >= $linea_a AND $i < $linea_a + $lppag2)
               {
                  $linea_size = 1;
                  $len = mb_strlen($lin->referencia.' '.$lin->descripcion);
                  while($len > 90)
                  {
                     $len -= 90;
                     $linea_size += 0.5;
                  }
                  
                  $aux = explode("\n", $lin->descripcion);
                  if( count($aux) > 1 )
                  {
                     $linea_size += 0.5 * ( count($aux) - 1);
                  }
                  
                  if($linea_size > 1)
                  {
                     $lppag2 -= $linea_size - 1;
                  }
               }
            }
            
            $linea_a += $lppag2;
            $this->numpaginas++;
         }
         
         if($this->numpaginas == 0)
         {
            $this->numpaginas = 1;
         }
      }
      
      if($this->impresion['print_dto'])
      {
         $this->impresion['print_dto'] = FALSE;
         
         /// leemos las líneas para ver si de verdad mostramos los descuentos
         foreach($lineas as $lin)
         {
            if($lin->dtopor != 0)
            {
               $this->impresion['print_dto'] = TRUE;
               break;
            }
         }
      }
      
      $dec_cantidad = 0;
      $multi_iva = FALSE;
      $multi_re = FALSE;
      $multi_irpf = FALSE;
      $iva = FALSE;
      $re = FALSE;
      $irpf = FALSE;
      /// leemos las líneas para ver si hay que mostrar los tipos de iva, re o irpf
      foreach($lineas as $i => $lin)
      {
         if( $lin->cantidad != intval($lin->cantidad) )
         {
            $dec_cantidad = 2;
         }
         
         if($iva === FALSE)
         {
            $iva = $lin->iva;
         }
         else if($lin->iva != $iva)
         {
            $multi_iva = TRUE;
         }
         
         if($re === FALSE)
         {
            $re = $lin->recargo;
         }
         else if($lin->recargo != $re)
         {
            $multi_re = TRUE;
         }
         
         if($irpf === FALSE)
         {
            $irpf = $lin->irpf;
         }
         else if($lin->irpf != $irpf)
         {
            $multi_irpf = TRUE;
         }
         
         /// restamos líneas al documento en función del tamaño de la descripción
         if($i >= $linea_actual AND $i < $linea_actual+$lppag)
         {
            $linea_size = 1;
            $len = mb_strlen($lin->referencia.' '.$lin->descripcion);
            while($len > 90)
            {
               $len -= 90;
               $linea_size += 0.5;
            }
            
            $aux = explode("\n", $lin->descripcion);
            if( count($aux) > 1 )
            {
               $linea_size += 0.5 * ( count($aux) - 1);
            }
            
            if($linea_size > 1)
            {
               $lppag -= $linea_size - 1;
            }
         }
      }
      
      /*
       * Creamos la tabla con las lineas del documento
       */
      $pdf_doc->new_table();
      $table_header = array(
          'alb' => '<b>'.ucfirst(FS_ALBARAN).'</b>',
          'descripcion' => '<b>Ref. + Descripción</b>',
          'cantidad' => '<b>Cant.</b>',
          'pvp' => '<b>Precio</b>',
      );
      
      /// ¿Desactivamos la columna de albaran?
      if( get_class_name($this->documento) == 'factura_cliente' )
      {
         if($this->impresion['print_alb'])
         {
            /// aunque esté activada, si la factura no viene de un albaran, la desactivamos
            $this->impresion['print_alb'] = FALSE;
            foreach($lineas as $lin)
            {
               if($lin->idalbaran)
               {
                  $this->impresion['print_alb'] = TRUE;
                  break;
               }
            }
         }
         
         if( !$this->impresion['print_alb'] )
         {
            unset($table_header['alb']);
         }
      }
      else
      {
         unset($table_header['alb']);
      }
      
      if($this->impresion['print_dto'] AND !isset($_GET['noval']) )
      {
         $table_header['dto'] = '<b>Dto.</b>';
      }
      
      if($multi_iva AND !isset($_GET['noval']) )
      {
         $table_header['iva'] = '<b>'.FS_IVA.'</b>';
      }
      
      if($multi_re AND !isset($_GET['noval']) )
      {
         $table_header['re'] = '<b>R.E.</b>';
      }
      
      if($multi_irpf AND !isset($_GET['noval']) )
      {
         $table_header['irpf'] = '<b>'.FS_IRPF.'</b>';
      }
      
      if( isset($_GET['noval']) )
      {
         unset($table_header['pvp']);
      }
      else
      {
         $table_header['importe'] = '<b>Importe</b>';
      }
      
      $pdf_doc->add_table_header($table_header);
      
      for($i = $linea_actual; (($linea_actual < ($lppag + $i)) AND ($linea_actual < count($lineas)));)
      {
         $descripcion = $pdf_doc->fix_html($lineas[$linea_actual]->descripcion);
         if( !is_null($lineas[$linea_actual]->referencia) )
         {
            if($lineas[$linea_actual]->pvpunitario == 0 )
            {
                $descripcion = '    ' . $descripcion;
            }else
            {
                $descripcion = '<b>'.$lineas[$linea_actual]->referencia.'</b> '.$descripcion;
            }
        }
         
         /// ¿El articulo tiene trazabilidad?
         $descripcion .= $this->generar_trazabilidad($lineas[$linea_actual]);
         
         $fila = array(
             'alb' => '-',
             'cantidad' => $this->show_numero($lineas[$linea_actual]->cantidad, $dec_cantidad),
             'descripcion' => $descripcion,
             'pvp' => $this->show_precio($lineas[$linea_actual]->pvpunitario, $this->documento->coddivisa, TRUE, FS_NF0_ART),
             'dto' => $this->show_numero($lineas[$linea_actual]->dtopor) . " %",
             'iva' => $this->show_numero($lineas[$linea_actual]->iva) . " %",
             're' => $this->show_numero($lineas[$linea_actual]->recargo) . " %",
             'irpf' => $this->show_numero($lineas[$linea_actual]->irpf) . " %",
             'importe' => $this->show_precio($lineas[$linea_actual]->pvptotal, $this->documento->coddivisa)
         );
         
         if($lineas[$linea_actual]->dtopor == 0)
         {
            $fila['dto'] = '';
         }
         
         if($lineas[$linea_actual]->recargo == 0)
         {
            $fila['re'] = '';
         }
         
         if($lineas[$linea_actual]->irpf == 0)
         {
            $fila['irpf'] = '';
         }
         
         if( !$lineas[$linea_actual]->mostrar_cantidad )
         {
            $fila['cantidad'] = '';
         }
         
         if( !$lineas[$linea_actual]->mostrar_precio )
         {
            $fila['pvp'] = '';
            $fila['dto'] = '';
            $fila['iva'] = '';
            $fila['re'] = '';
            $fila['irpf'] = '';
            $fila['importe'] = '';
         }
         
         if( get_class_name($lineas[$linea_actual]) == 'linea_factura_cliente' AND $this->impresion['print_alb'] )
         {
            $fila['alb'] = $lineas[$linea_actual]->albaran_numero();
         }
         
         if($lineas[$linea_actual]->pvpunitario == 0 )
         {
             $fila['pvp'] = '';
             $fila['cantidad'] = '';
             $fila['importe'] = '';
         }

         $pdf_doc->add_table_row($fila);
         $linea_actual++;
      }

      // Relleno azulito para cabecera de la tabla
      $pdf_doc->pdf->setColor(0.4, 0.50, 0.8);
      $pdf_doc->pdf->filledrectangle(28,625,530,15);
      $pdf_doc->pdf->setColor(0, 0, 0);

      $pdf_doc->save_table(
              array(
                  'fontSize' => 8,
                  'cols' => array(
                      'cantidad' => array('justification' => 'right'),
                      'pvp' => array('justification' => 'right'),
                      'dto' => array('justification' => 'right'),
                      'iva' => array('justification' => 'right'),
                      're' => array('justification' => 'right'),
                      'irpf' => array('justification' => 'right'),
                      'importe' => array('justification' => 'right')
                  ),
                  'width' => 530,
                  'shaded' => 1,
                  'titleFontSize' => 12,
                  'shadeCol' => array(0.95, 0.95, 0.95),
                  'lineCol' => array(0.3, 0.3, 0.3),
              )
      );
      
      /// ¿Última página?
      if( $linea_actual == count($lineas) )
      {
         if($this->documento->observaciones != '')
         {
            $pdf_doc->pdf->ezText("\n".$pdf_doc->fix_html($this->documento->observaciones), 9);
         }
      }
   }
   
   /**
    * Devuelve el texto con los números de serie o lotes de la $linea
    * @param linea_albaran_compra $linea
    * @return string
    */
   private function generar_trazabilidad($linea)
   {
      $lineast = array();
      if( get_class_name($linea) == 'linea_albaran_cliente' )
      {
         $lineast = $this->articulo_traza->all_from_linea('idlalbventa', $linea->idlinea);
      }
      else
      {
         $lineast = $this->articulo_traza->all_from_linea('idlfacventa', $linea->idlinea);
      }
      
      $txt = '';
      foreach($lineast as $lt)
      {
         $txt .= "\n";
         if($lt->numserie)
         {
            $txt .= 'N/S: '.$lt->numserie.' ';
         }
         
         if($lt->lote)
         {
            $txt .= 'Lote: '.$lt->lote;
         }
      }
      
      return $txt;
   }

   private function generar_pdf_datos_cliente(&$pdf_doc, &$lppag)
   {
      $tipo_doc = ucfirst(FS_ALBARAN);
      $rectificativa = FALSE;
      if( get_class_name($this->documento) == 'factura_cliente' )
      {
         if($this->documento->idfacturarect)
         {
            $tipo_doc = ucfirst(FS_FACTURA_RECTIFICATIVA);
            $rectificativa = TRUE;
         }
         else
         {
            $tipo_doc = 'Factura';
         }
      }
      
      $tipoidfiscal = FS_CIFNIF;
      if($this->cliente)
      {
         $tipoidfiscal = $this->cliente->tipoidfiscal;
      }
      
      $pdf_doc->pdf->ez['leftMargin'] = 45;
      $pdf_doc->set_y(725);
      $pdf_doc->pdf->ezText("<b>". mb_strtoupper($tipo_doc,'utf-8').": " . $this->documento->codigo .
                            "</b>" , 12, array('justification' => 'left'));
      if($rectificativa)
      {
         $pdf_doc->set_y(670);
         $pdf_doc->pdf->ezText("<b>ORIGINAL: " . $this->documento->codigorect .
                               "</b>" , 12, array('justification' => 'left'));
      }

      $pdf_doc->set_y(830);
      $pdf_doc->pdf->ezText($this->documento->fecha, 10, array('justification' => 'right'));
      $pdf_doc->set_y(730);
      $pdf_doc->pdf->ez['leftMargin'] = 260;
      $pdf_doc->pdf->ezText("<b>CLIENTE</b>",
                            10, array('justification' => 'center'));
      
      $pdf_doc->set_y(716);
      
      $tipoidfiscal = FS_CIFNIF;
      if($this->cliente)
      {
         $tipoidfiscal = $this->cliente->tipoidfiscal;
      }
      $pdf_doc->pdf->ezText("<b>" . $pdf_doc->fix_html($this->documento->nombrecliente) . "</b>",
                            10, array('justification' => 'left'));
      $pdf_doc->pdf->ez['leftMargin'] = 264;
      $pdf_doc->pdf->ezText("CIF/NIF: " . $this->documento->cifnif,
                            8, array('justification' => 'left'));

      $direccion = $this->documento->direccion;
      if($this->documento->apartado)
      {
         $direccion .= ' - '.ucfirst(FS_APARTADO).': '.$this->documento->apartado;
      }
      if($this->documento->codpostal)
      {
         $direccion .= ' - CP: '.$this->documento->codpostal;
      }

      $direccion .= ' - '.$this->documento->ciudad;
      if($this->documento->provincia)
      {
         $direccion .= ' ('.$this->documento->provincia.')';
      }
        
      $pdf_doc->pdf->ezText($pdf_doc->fix_html($direccion),
                            8, array('justification' => 'left'));

      if($this->documento->apartado)
      {
         $pdf_doc->pdf->ezText("<b>".ucfirst(FS_APARTADO).": </b> ".$this->documento->apartado,
                               8, array('justification' => 'left'));
      }

      if(!$this->cliente)
      {
         /// nada
      }
      else if($this->cliente->telefono1)
      {
         if($this->cliente->telefono2)
         {
            $pdf_doc->pdf->ezText("<b>Teléfonos:</b> ".$this->cliente->telefono1 . " - " .
                                  $this->cliente->telefono2,
                                  8, array('justification' => 'left'));
         } else
         {
            $pdf_doc->pdf->ezText("<b>Teléfono:</b> ".$this->cliente->telefono1,
                                  8, array('justification' => 'left'));                  
         }
      }
      else if($this->cliente->telefono2)
      {
         $pdf_doc->pdf->ezText("<b>Teléfono:</b> ".$this->cliente->telefono2,
                               8, array('justification' => 'left'));
      }

      if($this->empresa->codpais != 'ESP')
      {
         $pdf_doc->pdf->ezText("<b>Régimen ".FS_IVA.":</b> ".$this->cliente->regimeniva,
                               8, array('justification' => 'left'));
      }            

      if($this->cliente->numeroproveedor)
      {
         $pdf_doc->pdf->ez['leftMargin'] = 450;
         $pdf_doc->set_y(744);
         $pdf_doc->pdf->ezText("<b>N. Proveedor:</b> ".$this->cliente->numeroproveedor,
                               8, array('justification' => 'left'));
      }

      $pdf_doc->pdf->ez['leftMargin'] = 50;
      $pdf_doc->set_y(690);

      if($this->documento->numero2)
      {
         $pdf_doc->pdf->ezText("<b>" . ucfirst(FS_NUMERO2) . ":</b> ".$this->documento->numero2,
                               8, array('justification' => 'left'));
      } else {
         $pdf_doc->pdf->ezText("<b>" . ucfirst(FS_NUMERO2) . ":</b> N/A",
                               8, array('justification' => 'left'));
      }

      if(isset($this->documento->persona_contacto))
      {
         if($this->documento->persona_contacto)
         {
            $pdf_doc->pdf->ezText("<b>Contacto:</b> ".$this->documento->persona_contacto,
                                  8, array('justification' => 'left'));
         } else {
            $pdf_doc->pdf->ezText("<b>Contacto:</b> N/A",
                                  8, array('justification' => 'left'));               
         }        
      }

      if(isset($this->documento->persona_contacto))
      {
         if($this->documento->actuacion_en)
         {
            $pdf_doc->pdf->ezText("<b>Actuacion En:</b> ".$this->documento->actuacion_en,
                                  8, array('justification' => 'left'));
         } else {
            $pdf_doc->pdf->ezText("<b>Actuacion En:</b> N/A",
                                  8, array('justification' => 'left'));               
         }
      }

      $pdf_doc->pdf->ezText("\n", 10);
      $pdf_doc->pdf->ez['leftMargin'] = 40;
      $pdf_doc->set_y(640);
      
      

   }
   
   private function generar_pdf_totales(&$pdf_doc, &$lineas_iva, $pagina)
   {
      if( isset($_GET['noval']) )
      {
         $pdf_doc->pdf->addText(10, 10, 8, $pdf_doc->center_text('Página '.$pagina.'/'.$this->numpaginas, 250) );
      }
      else
      {
         // Relleno azulito para cabecera de la tabla
         $pdf_doc->pdf->setColor(0.4, 0.50, 0.8);
         $pdf_doc->pdf->filledrectangle(33,62,520,18);
         $pdf_doc->pdf->setColor(0, 0, 0);
         /*
          * Rellenamos la última tabla de la página:
          * 
          * Página            Neto    IVA   Total
          */
         $pdf_doc->new_table();
         $titulo = array('pagina' => '<b>Página</b>', 'neto' => '<b>Neto</b>',);
         $fila = array(
             'pagina' => $pagina . '/' . $this->numpaginas,
             'neto' => $this->show_precio($this->documento->neto, $this->documento->coddivisa),
         );
         $opciones = array(
             'cols' => array(
                 'pagina' => array('justification' => 'center'),
                 'neto' => array('justification' => 'right'),
             ),
             'showLines' => 1,
             'shaded' => 2,
             'shadeCol2' => array(0.95, 0.95, 0.95),
             'lineCol' => array(0.3, 0.3, 0.3),
             'width' => 520
         );
         foreach($lineas_iva as $li)
         {
            $imp = $this->impuesto->get($li['codimpuesto']);
            if($imp)
            {
               $titulo['iva'.$li['iva']] = '<b>'.$imp->descripcion.'</b>';
            }
            else
               $titulo['iva'.$li['iva']] = '<b>'.FS_IVA.' '.$li['iva'].'%</b>';
            
            $fila['iva'.$li['iva']] = $this->show_precio($li['totaliva'], $this->documento->coddivisa);
            
            if($li['totalrecargo'] != 0)
            {
               $fila['iva'.$li['iva']] .= "\nR.E. ".$li['recargo']."%: ".$this->show_precio($li['totalrecargo'], $this->documento->coddivisa);
            }
               
            $opciones['cols']['iva'.$li['iva']] = array('justification' => 'right');
         }
         
         if($this->documento->totalirpf != 0)
         {
            $titulo['irpf'] = '<b>'.FS_IRPF.' '.$this->documento->irpf.'%</b>';
            $fila['irpf'] = $this->show_precio($this->documento->totalirpf);
            $opciones['cols']['irpf'] = array('justification' => 'right');
         }
         
         $titulo['liquido'] = '<b>Total</b>';
         $fila['liquido'] = $this->show_precio($this->documento->total, $this->documento->coddivisa);
         $opciones['cols']['liquido'] = array('justification' => 'right');
         
         $pdf_doc->add_table_header($titulo);
         $pdf_doc->add_table_row($fila);
         $pdf_doc->save_table($opciones);
      }
   }
   
   private function generar_pdf_albaran($archivo = FALSE)
   {
      if(!$archivo)
      {
         /// desactivamos la plantilla HTML
         $this->template = FALSE;
      }
      
      /// Creamos el PDF y escribimos sus metadatos
      $pdf_doc = new fs_pdf();
      $pdf_doc->pdf->addInfo('Title', ucfirst(FS_ALBARAN).' '. $this->documento->codigo);
      $pdf_doc->pdf->addInfo('Subject', ucfirst(FS_ALBARAN).' de cliente ' . $this->documento->codigo);
      $pdf_doc->pdf->addInfo('Author', $this->empresa->nombre);
      
      $lineas = $this->documento->get_lineas();
      $lineas_iva = $pdf_doc->get_lineas_iva($lineas);
      if($lineas)
      {
         $linea_actual = 0;
         $pagina = 1;
         
         /// imprimimos las páginas necesarias
         while( $linea_actual < count($lineas) )
         {
            $lppag = 35;
            
            /// salto de página
            if($linea_actual > 0)
            {
               $pdf_doc->pdf->ezNewPage();
            }
            
            $pdf_doc->generar_pdf_cabecera($this->empresa, $lppag);
            $this->generar_pdf_datos_cliente($pdf_doc, $lppag);
            $this->generar_pdf_lineas($pdf_doc, $lineas, $linea_actual, $lppag);
            
            $pdf_doc->set_y(80);
            $this->generar_pdf_totales($pdf_doc, $lineas_iva, $pagina);
            $pagina++;
         }
      }
      else
      {
         $pdf_doc->pdf->ezText('¡'.ucfirst(FS_ALBARAN).' sin líneas!', 20);
      }
      
      if($archivo)
      {
         if( !file_exists('tmp/'.FS_TMP_NAME.'enviar') )
         {
            mkdir('tmp/'.FS_TMP_NAME.'enviar');
         }
         
         $pdf_doc->save('tmp/'.FS_TMP_NAME.'enviar/'.$archivo);
      }
      else
         $pdf_doc->show(FS_ALBARAN.'_'.$this->documento->codigo.'.pdf');
   }
   
   private function generar_pdf_factura($tipo = 'simple', $archivo = FALSE)
   {
      if(!$archivo)
      {
         /// desactivamos la plantilla HTML
         $this->template = FALSE;
      }
      
      /// Creamos el PDF y escribimos sus metadatos
      $pdf_doc = new fs_pdf();
      $pdf_doc->pdf->addInfo('Title', ucfirst(FS_FACTURA).' '. $this->documento->codigo);
      $pdf_doc->pdf->addInfo('Subject', ucfirst(FS_FACTURA).' ' . $this->documento->codigo);
      $pdf_doc->pdf->addInfo('Author', $this->empresa->nombre);
      
      $lineas = $this->documento->get_lineas();
      $lineas_iva = $pdf_doc->get_lineas_iva($lineas);
      if($lineas)
      {
         $linea_actual = 0;
         $pagina = 1;
         
         /// imprimimos las páginas necesarias
         while( $linea_actual < count($lineas) )
         {
            $lppag = 35;
            
            /// salto de página
            if($linea_actual > 0)
            {
               $pdf_doc->pdf->ezNewPage();
            }
            
            /*
             * Creamos la cabecera de la página, en este caso para el modelo carta
             */
            if($tipo == 'carta')
            {
               $pdf_doc->generar_pdf_cabecera($this->empresa, $lppag);
               
               $pdf_doc->pdf->ez['leftMargin'] = 125;
               $direccion = $this->documento->nombrecliente."\n".$this->documento->direccion;
               if($this->documento->apartado)
               {
                  $direccion .= "\n " . ucfirst(FS_APARTADO) . ": " . $this->documento->apartado;
               }
               
               if($this->documento->codpostal)
               {
                  $direccion .= "\n CP: " . $this->documento->codpostal . ' - ';
               }
               else
               {
                  $direccion .= "\n";
               }
               $direccion .= $this->documento->ciudad . "\n(" . $this->documento->provincia . ")";
               
               $pdf_doc->new_table();
               $pdf_doc->add_table_row(
                  array(
                      'campos' => "<b>".ucfirst(FS_FACTURA).":</b>\n<b>Fecha:</b>\n<b>".$this->cliente->tipoidfiscal.":</b>",
                      'factura' => $this->documento->codigo."\n".$this->documento->fecha."\n".$this->documento->cifnif,
                      'cliente' => $pdf_doc->fix_html($direccion)
                  )
               );
               $pdf_doc->save_table(
                  array(
                      'cols' => array(
                          'campos' => array('justification' => 'right', 'width' => 100),
                          'factura' => array('justification' => 'left'),
                          'cliente' => array('justification' => 'right')
                      ),
                      'showLines' => 0,
                      'width' => 420
                  )
               );
               $pdf_doc->pdf->ezText("\n\n\n", 14);
               $pdf_doc->pdf->ez['leftMargin'] = 35;
            }
            else /// esta es la cabecera de la página para el modelo 'simple'
            {
               $pdf_doc->generar_pdf_cabecera($this->empresa, $lppag);
               $this->generar_pdf_datos_cliente($pdf_doc, $lppag);
            }
            
            $this->generar_pdf_lineas($pdf_doc, $lineas, $linea_actual, $lppag, $this->documento);
            
            if( $linea_actual == count($lineas) )
            {
               if( !$this->documento->pagada AND $this->impresion['print_formapago'] )
               {
                  $fp0 = new forma_pago();
                  $forma_pago = $fp0->get($this->documento->codpago);
                  if($forma_pago)
                  {
                     $texto_pago = "\n<b>Forma de pago</b>: ".$forma_pago->descripcion;
                     
                     if(!$forma_pago->imprimir)
                     {
                        /// nada
                     }
                     else if($forma_pago->domiciliado)
                     {
                        $cbc0 = new cuenta_banco_cliente();
                        $encontrada = FALSE;
                        foreach($cbc0->all_from_cliente($this->documento->codcliente) as $cbc)
                        {
                           $texto_pago .= "\n<b>Domiciliado en</b>: ";
                           if($cbc->iban)
                           {
                              $texto_pago .= $cbc->iban(TRUE);
                           }
                           
                           if($cbc->swift)
                           {
                              $texto_pago .= "\n<b>SWIFT/BIC</b>: ".$cbc->swift;
                           }
                           $encontrada = TRUE;
                           break;
                        }
                        if(!$encontrada)
                        {
                           $texto_pago .= "\n<b>El cliente no tiene cuenta bancaria asignada.</b>";
                        }
                     }
                     else if($forma_pago->codcuenta)
                     {
                        $cb0 = new cuenta_banco();
                        $cuenta_banco = $cb0->get($forma_pago->codcuenta);
                        if($cuenta_banco)
                        {
                           if($cuenta_banco->iban)
                           {
                              $texto_pago .= "\n<b>IBAN</b>: ".$cuenta_banco->iban(TRUE);
                           }
                           
                           if($cuenta_banco->swift)
                           {
                              $texto_pago .= "\n<b>SWIFT o BIC</b>: ".$cuenta_banco->swift;
                           }
                        }
                     }
                     
                     $texto_pago .= "\n<b>Vencimiento</b>: ".$this->documento->vencimiento;
                     $pdf_doc->pdf->ezText($texto_pago, 9);
                  }
               }
            }
            
            $pdf_doc->set_y(80);
            $this->generar_pdf_totales($pdf_doc, $lineas_iva, $pagina);
            
            /// pié de página para la factura
            if($this->empresa->pie_factura)
            {
               $pdf_doc->pdf->addText(10, 10, 8, $pdf_doc->center_text($pdf_doc->fix_html($this->empresa->pie_factura), 180) );
            }
            
            $pagina++;
         }
      }
      else
      {
         $pdf_doc->pdf->ezText('¡'.ucfirst(FS_FACTURA).' sin líneas!', 20);
      }
      
      if($archivo)
      {
         if( !file_exists('tmp/'.FS_TMP_NAME.'enviar') )
         {
            mkdir('tmp/'.FS_TMP_NAME.'enviar');
         }
         
         $pdf_doc->save('tmp/'.FS_TMP_NAME.'enviar/'.$archivo);
      }
      else
         $pdf_doc->show(FS_FACTURA.'_'.$this->documento->codigo.'.pdf');
   }
   
   private function enviar_email($doc, $tipo = 'simple')
   {
      if( $this->empresa->can_send_mail() )
      {
         if($doc == 'factura')
         {
            $filename = 'factura_'.$this->documento->codigo.'.pdf';
            $this->generar_pdf_factura($tipo, $filename);
         }
         else
         {
            $filename = 'albaran_'.$this->documento->codigo.'.pdf';
            $this->generar_pdf_albaran($filename);
         }
         
         $razonsocial = $this->documento->nombrecliente;
         if($this->cliente)
         {
            if( $_POST['email'] != $this->cliente->email AND isset($_POST['guardar']) )
            {
               $this->cliente->email = $_POST['email'];
               $this->cliente->save();
            }
         }
         
         if( file_exists('tmp/'.FS_TMP_NAME.'enviar/'.$filename) )
         {
            $mail = $this->empresa->new_mail();
            $mail->FromName = $this->user->get_agente_fullname();
            
            if($_POST['de'] != $mail->From)
            {
               $mail->addReplyTo($_POST['de'], $mail->FromName);
            }
            
            $mail->addAddress($_POST['email'], $razonsocial);
            if($_POST['email_copia'])
            {
               if( isset($_POST['cco']) )
               {
                  $mail->addBCC($_POST['email_copia'], $razonsocial);
               }
               else
               {
                  $mail->addCC($_POST['email_copia'], $razonsocial);
               }
            }
            
            if($doc == 'factura')
            {
               $mail->Subject = $this->empresa->nombre . ': Su factura '.$this->documento->codigo;
            }
            else
            {
               $mail->Subject = $this->empresa->nombre . ': Su '.FS_ALBARAN.' '.$this->documento->codigo;
            }
            
            if( $this->is_html($_POST['mensaje']) )
            {
               $mail->AltBody = strip_tags($_POST['mensaje']);
               $mail->msgHTML($_POST['mensaje']);
               $mail->isHTML(TRUE);
            }
            else
            {
               $mail->Body = $_POST['mensaje'];
            }
            
            $mail->addAttachment('tmp/'.FS_TMP_NAME.'enviar/'.$filename);
            if( is_uploaded_file($_FILES['adjunto']['tmp_name']) )
            {
               $mail->addAttachment($_FILES['adjunto']['tmp_name'], $_FILES['adjunto']['name']);
            }
            
            if( $this->empresa->mail_connect($mail) )
            {
               if( $mail->send() )
               {
                  $this->new_message('Mensaje enviado correctamente.');
                  
                  /// nos guardamos la fecha de envío
                  $this->documento->femail = $this->today();
                  $this->documento->save();
                  
                  $this->empresa->save_mail($mail);
               }
               else
                  $this->new_error_msg("Error al enviar el email: " . $mail->ErrorInfo);
            }
            else
               $this->new_error_msg("Error al enviar el email: " . $mail->ErrorInfo);
            
            unlink('tmp/'.FS_TMP_NAME.'enviar/'.$filename);
         }
         else
            $this->new_error_msg('Imposible generar el PDF.');
      }
   }
   
   public function is_html($txt)
   {
      if( stripos($txt, '<html') === FALSE )
      {
         return FALSE;
      }
      else
      {
         return TRUE;
      }
   }
}
