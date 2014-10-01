<?php
namespace Transparente\Model;

class ProveedorModel extends AbstractModel
{
    const PAGE_MAX = 100;

    public function findAll()
    {
        return $this->findBy($criteria = [], $orderBy = ['nombre' => 'ASC']);
    }

    public function findByNoDomicilioFiscal()
    {
        $dql = 'SELECT Proveedor
                FROM Transparente\Model\Entity\Proveedor Proveedor
                WHERE Proveedor.domicilio_fiscal IS NULL
                ORDER BY Proveedor.nombre
                ';
        $query = $this->getEntityManager()->createQuery($dql);
        return $query->getResult();

    }

    /**
     * Lee todos los datos del proveedor según su ID
     *
     * @param int $id
     * @return array
     */
    public function scrap($id)
    {
        $url               = "http://guatecompras.gt/proveedores/consultaDetProvee.aspx?rqp=8&lprv={$id}";
        $páginaDelProveedor = ScraperModel::getCachedUrl($url);

        /**
         * Que valores vamos a buscar via xpath en la página del proveedor
         *
         * Usamos de nombre los campos de la base de datos para después solo volcar el arreglo con los resultados directo a
         * la DB.
         *
         * @var array
         */
        $xpaths = [
            'nombre'               => '//*[@id="MasterGC_ContentBlockHolder_lblNombreProv"]',
            'nit'                  => '//*[@id="MasterGC_ContentBlockHolder_lblNIT"]',
            'status'               => '//*[@id="MasterGC_ContentBlockHolder_lblHabilitado"]',
            'tiene_acceso_sistema' => '//*[@id="MasterGC_ContentBlockHolder_lblContraSnl"]',
            'domicilio_fiscal'     => [
                'updated'      => 'div#MasterGC_ContentBlockHolder_divDomicilioFiscal span.AvisoGrande span.AvisoGrande',
                'departamento' => '//*[@id="MasterGC_ContentBlockHolder_pnl_domicilioFiscal2"]//tr[1]//td[2]',
                'municipio'    => '//*[@id="MasterGC_ContentBlockHolder_pnl_domicilioFiscal2"]//tr[2]//td[2]',
                'direccion'    => '//*[@id="MasterGC_ContentBlockHolder_pnl_domicilioFiscal2"]//tr[3]//td[2]',
                'telefonos'    => '//*[@id="MasterGC_ContentBlockHolder_pnl_domicilioFiscal2"]//tr[4]//td[2]',
                'fax'          => '//*[@id="MasterGC_ContentBlockHolder_pnl_domicilioFiscal2"]//tr[5]//td[2]',
            ],
            'domicilio_comercial'     => [
                'updated'      => null,
                'departamento' => '//*[@id="MasterGC_ContentBlockHolder_pnl_domicilioComercial2"]//tr[3]//td[2]',
                'municipio'    => '//*[@id="MasterGC_ContentBlockHolder_pnl_domicilioComercial2"]//tr[4]//td[2]',
                'direccion'    => '//*[@id="MasterGC_ContentBlockHolder_pnl_domicilioComercial2"]//tr[5]//td[2]',
                'telefonos'    => '//*[@id="MasterGC_ContentBlockHolder_pnl_domicilioComercial2"]//tr[6]//td[2]',
                'fax'          => '//*[@id="MasterGC_ContentBlockHolder_pnl_domicilioComercial2"]//tr[7]//td[2]',
            ],
            'url'                 => '//*[@id="MasterGC_ContentBlockHolder_pnl_domicilioComercial2"]//tr[1]//td[2]',
            'email'               => '//*[@id="MasterGC_ContentBlockHolder_pnl_domicilioComercial2"]//tr[2]//td[2]',
            'rep_legales_updated' => '//*[@id="MasterGC_ContentBlockHolder_divRepresentantesLegales"]//span/span',
        ];

        $proveedor = ['id' => $id] + ScraperModel::fetchData($xpaths, $páginaDelProveedor);

        // después de capturar los datos, hacemos un postproceso

        $proveedor['status']               = ($proveedor['status'] == 'HABILITADO');
        $proveedor['tiene_acceso_sistema'] = ($proveedor['tiene_acceso_sistema'] == 'CON CONTRASEÑA');
        // descartar direcciones vacías
        if ($proveedor['domicilio_fiscal']['direccion'] == '[--No Especificado--]' ||
            $proveedor['domicilio_fiscal']['municipio'] == '[--No Especificado--]') {
            unset($proveedor['domicilio_fiscal']);
        }
        if ($proveedor['domicilio_comercial']['direccion'] == '[--No Especificado--]' ||
            $proveedor['domicilio_comercial']['municipio'] == '[--No Especificado--]') {
            unset($proveedor['domicilio_comercial']);
        }

        // algunas fechas no están bien parseadas
        $proveedor['rep_legales_updated']  = strptime($proveedor['rep_legales_updated'], '(Datos recibidos de la SAT el: %d.%b.%Y %T ');
        $proveedor['rep_legales_updated']  = 1900+$proveedor['rep_legales_updated']['tm_year']
                                            . '-' . (1 + $proveedor['rep_legales_updated']['tm_mon'])
                                            . '-' . ($proveedor['rep_legales_updated']['tm_mday'])
                                            ;
        $proveedor['url']   = ($proveedor['url']   != '[--No Especificado--]') ? $proveedor['url'] : null;
        $proveedor['email'] = ($proveedor['email'] != '[--No Especificado--]') ? $proveedor['email'] : null;
        return $proveedor;
    }

    /**
     * Conseguir todos los proveedores adjudicados del año en curso
     *
     * @return int[]
     *
     * @todo Detectar cuantas páginas hay que leer. No necesitar usar una constante para saber si llegamos al final.
     *       Al parar cuando recibimos una página con solo proveedores que ya leimos, contamos 2,550 proveedores
     *
     * @todo Hay diferentes páginas donde sale el mismo proveedor, hay que hacer un reporte de eso (issue #21)
     *       ERROR: Se encontró proveedor duplicado (2025239)  en las páginas 49 y  50.
     *
     * @todo Reducir las variables de las llaves que son constantes entre diferentes paginadores, seteándolas en el
     *       scraper, y seteando solo las que son diferentes por paginador como parámetros.
     */
    public function scrapList()
    {
        $year        = date('Y');
        $proveedores = [];
        $pagerKeys   = [
            '_body:MasterGC$ContentBlockHolder$ScriptManager1' => 'MasterGC$ContentBlockHolder$UpdatePanel1|MasterGC$ContentBlockHolder$dgResultado$ctl54$ctl',
            '__EVENTTARGET'                                    => 'MasterGC$ContentBlockHolder$dgResultado$ctl54$ctl',
            '__VIEWSTATE'                                      => '/wEPDwUKMTI4MzUzOTE3NA8WAh4CbFkC3g8WAmYPZBYCAgMPZBYCAgEPZBYCAgUPZBYCAgUPZBYCZg9kFgQCAw8WAh4EVGV4dAXNAjx0YWJsZSBjbGFzcz0iVGl0dWxvUGFnMSIgY2VsbFNwYWNpbmc9IjAiIGNlbGxQYWRkaW5nPSIyIiBhbGlnbj0iY2VudGVyIj48dHI+PHRkPjx0YWJsZSBjbGFzcz0iVGl0dWxvUGFnMiIgY2VsbFNwYWNpbmc9IjAiIGNlbGxQYWRkaW5nPSIyIj48dHI+PHRkIGNsYXNzPSJUaXR1bG9QYWczIiBhbGlnbj0iY2VudGVyIj48dGFibGUgY2xhc3M9IlRhYmxhRm9ybTMiIGNlbGxTcGFjaW5nPSIzIiBjZWxsUGFkZGluZz0iNCI+PHRyIGNsYXNzPSJFdGlxdWV0YUZvcm0yIj48dGQ+QcOxbzogMjAxNDwvdGQ+PC90cj48L3RhYmxlPjwvdGQ+PC90cj48L3RhYmxlPjwvdGQ+PC90cj48L3RhYmxlPmQCBw88KwALAQAPFgoeC18hSXRlbUNvdW50AjIeCERhdGFLZXlzFgAeCVBhZ2VDb3VudAJNHhVfIURhdGFTb3VyY2VJdGVtQ291bnQC/h0eEFZpcnR1YWxJdGVtQ291bnQC/h1kZGTFGnAqD6UqwZ6veVDVd8I1rSzrhg==',
            '__EVENTVALIDATION'                                => '/wEdAA14XElF3qXk6b0iXGg7E00zDgb8Uag+idZmhp4z8foPgz4xN15UhY4K7pA9ni2czGB1NCd9VnYGmPGPtkDAtNQeEDIBsVJcI17AvX4wvuIJ5AgMop+g+rIcjfLnqU7sIEd1r49BNud9Gzhdq5Du6Cuaivj/J0Sb6VUF9yYCq0O32nVzQBnAbvzxCHDPy/dQNW4JRFkop3STShyOPuu+QjyFyEKGLUzsAW/S22pN4CQ1k/PmspiPnyFdAbsK7K0ZtyIv/uu03tEXAoLdp793x+CRlm7Yn37MSDqo7lpN9Z9v4u6Js8E=',
        ];
        $proveedorEnPágina = [];
        $encontrados       = false;
        $duplicados        = 0;
        $page = 0;
        do {
            $page++;
            $html = ScraperModel::getCachedUrl(
                    'http://guatecompras.gt/proveedores/consultaProveeAdjLst.aspx?lper='.$year,
                    ScraperModel::PAGE_MODE_PAGER,
                    $pagerKeys,
                    "proveedores-list-page-$page"
            );
            $xpath           = "//a[starts-with(@href, './consultaDetProveeAdj.aspx')]";
            $proveedoresList = $html->queryXpath($xpath);
            $encontrados     = count($proveedoresList);
            foreach ($proveedoresList as $nodo) {
                /* @var $proveedor DOMElement */
                // El link apunta a las adjudicaciones/projectos del proveedor, pero de aquí sacamos el ID del proveedor
                $url           = parse_url($nodo->getAttribute('href'));
                parse_str($url['query'], $url);
                $idProveedor   = (int) $url['lprv'];
                if (!in_array($idProveedor, $proveedores)) {
                    $proveedorEnPágina[$idProveedor] = $page;
                    $proveedores[]                   = $idProveedor;
                } else {
                    $duplicados++;
                    $encontrados--;
                    $páginaOriginal = $proveedorEnPágina[$idProveedor];
                    echo "ERROR: Se encontró proveedor duplicado ($idProveedor)  en las páginas $páginaOriginal y $page\n";
                }
            }
        // } while($encontrados > 0);
        } while ($page <= self::PAGE_MAX);
        $total = count($proveedores);
        echo "***** ***** ***** ***** LOG: proveedores por leer: $total, proveedores duplicados: $duplicados\n";
        return $proveedores;
    }

    /**
     * Obtiene los nombres comerciales de los proveedores
     *
     * @param int $id
     * @return array
     */
    public function scrapNombresComerciales($id)
    {
        $página  = ScraperModel::getCachedUrl('http://guatecompras.gt/proveedores/consultaProveeNomCom.aspx?rqp=8&lprv='.$id);
        $xpath   = '//*[@id="MasterGC_ContentBlockHolder_dgResultado"]//tr[not(@class="TablaTitulo")]/td[2]';
        $nodos   = $página->queryXpath($xpath);
        $nombres = [];
        foreach ($nodos as $nodo) {
            $nombre = $nodo->nodeValue;
            if (in_array($nombre, $nombres)) continue;
            $nombres[] = $nombre;
        }
        sort($nombres);
        return $nombres;
    }
}