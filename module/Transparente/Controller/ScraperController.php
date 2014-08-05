<?php
namespace Transparente\Controller;

use Transparente\Model\DomicilioModel;
use Transparente\Model\ProveedorModel;
use Transparente\Model\RepresentanteLegalModel;
use Transparente\Model\Entity\EmpleadoMunicipal;

use Zend\Mvc\Controller\AbstractActionController;

/**
 * ScraperController
 *
 * Tiempo aprox para leer solo los proveedores, 00:01:20
 *
 * @property Transparente\Model\ProveedoresTable $proveedoresTable
 *
 */
class ScraperController extends AbstractActionController
{
    private function scrapEmpleadosMunicipales()
    {
        $domicilioModel       = $this->getServiceLocator()->get('Transparente\Model\DomicilioModel');
        /* @var $domicilioModel DomicilioModel */
        $partidoPolíticoModel = $this->getServiceLocator()->get('Transparente\Model\PartidoPoliticoModel');
        /* @var $partidoPolítico PartidoPoliticoModel */
        $db = $this->getServiceLocator()->get('Doctrine\ORM\EntityManager');
        /* @var $db Doctrine\ORM\EntityManager */

        $path  = realpath(__DIR__.'/../../../');
        $path .= '/data/xls2import/';
        $path .= 'empleados_municipales.csv';
        if (($handle = fopen($path, 'r')) === FALSE) {
            throw new \Exception("No se pudo leer el CSV '$path' para importar los empleados municipales");
        }
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if ($data[3] == 'VACANTE') continue;
            $municipio       = $domicilioModel->detectarMunicipio($data[0], $data[1], 'nombre');
            $partidoPolítico = $partidoPolíticoModel->findByIniciales($data[3])[0];
            if (!$partidoPolítico) {
                echo '<pre><strong>DEBUG::</strong> '.__FILE__.' +'.__LINE__."\n"; var_dump($data);
                throw new \Exception("No se encontró el partido político {$data[3]}");
            }

            $data = [
                'nombre1'   => $data[5],
                'nombre2'   => $data[6],
                'apellido1' => $data[7],
                'apellido2' => $data[8],
                'apellido3' => $data[9],
                'cargo'     => $data[2],
            ];

            $empleadoMunicipal = new EmpleadoMunicipal();
            $empleadoMunicipal->exchangeArray($data);
            $empleadoMunicipal->setPartidoPolitico($partidoPolítico);
            $empleadoMunicipal->setMunicipio($municipio);
            $db->persist($empleadoMunicipal);
        }
        fclose($handle);
        $db->flush();
    }

    private function scrapProyectosAdjudicados()
    {
        $proyectoModel  = $this->getServiceLocator()->get('Transparente\Model\ProyectoModel');
        /* @var $protectoModel ProyectoModel */
        $proveedorModel = $this->getServiceLocator()->get('Transparente\Model\ProveedorModel');
        /* @var $proveedorModel ProveedorModel */

        $proveedores    = $proveedorModel->findAll();
        $count = 0;
        foreach($proveedores as $proveedor) {
            $proyectosList = $proyectoModel->scrapList($proveedor);
            foreach ($proyectosList as $id) {
                //$proyecto = $proyectoModel->scrap($id,$proveedor->getId());
                //echo '<pre><strong>DEBUG::</strong> '.__FILE__.' +'.__LINE__."\n"; \Doctrine\Common\Util\Debug::dump($proyecto); die();




            }
            if ($count++ > 3) break;
        }



    }

    private function scrapProveedores()
    {
        $proveedorModel = $this->getServiceLocator()->get('Transparente\Model\ProveedorModel');
        /* @var $proveedorModel ProveedorModel */
        $repModel    = $this->getServiceLocator()->get('Transparente\Model\RepresentanteLegalModel');
        /* @var $repModel RepresentanteLegalModel */
        $domicilioModel = $this->getServiceLocator()->get('Transparente\Model\DomicilioModel');
        /* @var $domicilioModel DomicilioModel */

        $totales = [
            'proveedores' => 0,
            'domicilios'  => 0,
            'repLegales'  => 0,
        ];

        $proveedores = $proveedorModel->scrapList();
        foreach ($proveedores as $idProveedor) {
            $totales['proveedores']++;
            $data      = $proveedorModel->scrap($idProveedor);
            $data     += ['nombres_comerciales'    => $proveedorModel->scrapNombresComerciales($idProveedor)];
            $data     += ['representantes_legales' => $repModel->scrapRepresentantesLegales($idProveedor)];
            $proveedor = new \Transparente\Model\Entity\Proveedor();
            $proveedor->exchangeArray($data);

            if (!empty($data['domicilio_fiscal'])) {
                $totales['domicilios']++;
                $domicilio = new \Transparente\Model\Entity\Domicilio();
                $domicilio->exchangeArray($data['domicilio_fiscal']);
                try {
                    $domicilio = $domicilioModel->createFromScrappedData($data['domicilio_fiscal']);
                    if ($domicilio) {
                        $proveedor->setDomicilioFiscal($domicilio);
                    }
                } catch (\Exception $e) {
                    echo '<pre><strong>DEBUG::</strong> '.__FILE__.' +'.__LINE__."\n"; var_dump($e->getMessage(), $data); die();
                }
            }

            if (!empty($data['domicilio_comercial'])) {
                $totales['domicilios']++;
                $domicilio = new \Transparente\Model\Entity\Domicilio();
                $domicilio->exchangeArray($data['domicilio_comercial']);
                try {
                    $domicilio = $domicilioModel->createFromScrappedData($data['domicilio_comercial']);
                    if ($domicilio) {
                        $proveedor->setDomicilioComercial($domicilio);
                    }
                } catch (\Exception $e) {
                    echo '<pre><strong>DEBUG::</strong> '.__FILE__.' +'.__LINE__."\n"; var_dump($e->getMessage(), $data); die();
                }
            }

            foreach ($data['nombres_comerciales'] as $nombre) {
                $nombreComercial = new \Transparente\Model\Entity\ProveedorNombreComercial();
                $nombreComercial->setNombre($nombre);
                $proveedor->appendNombreComercial($nombreComercial);
            }

            foreach ($data['representantes_legales'] as $idRep) {
                $totales['repLegales']++;
                /* @var $domicilioModel DomicilioModel */
                $repLegal = $repModel->scrap($idRep);
                $proveedor->appendRepresentanteLegal($repLegal);
            }
            // echo '<pre><strong>DEBUG::</strong> '.__FILE__.' +'.__LINE__."\n"; Doctrine\Common\Util\Debug::dump($proveedor); die();
            // echo '<pre><strong>DEBUG::</strong> '.__FILE__.' +'.__LINE__."\n"; var_dump($data); die();
            $proveedorModel->save($proveedor);
       }
        $db = $this->getServiceLocator()->get('Doctrine\ORM\EntityManager');
        /* @var $db Doctrine\ORM\EntityManager */
        $db->flush();
    }

    /**
     * Iniciando el scraper
     *
     * @todo preguntar en el CLI si se quiere hacer cada paso
     * @todo reiniciar la DB desde PHP y no desde el bash
     */
    public function indexAction()
    {
        $request = $this->getRequest();
        if (!$request instanceof \Zend\Console\Request){
            throw new \RuntimeException('Scraper solo puede ser corrido desde linea de comando.');
        }
        ini_set('memory_limit', -1);

        // la lectura de los empleados municipales son datos locales
        // $this->scrapEmpleadosMunicipales();
        // empezamos la barrida de Guatecompras buscando los proveedores
        // $this->scrapProveedores();
        // Ahora que ya tenemos los proveedores en la base de datos, ya podemos importar los proyectos
        $this->scrapProyectosAdjudicados();

    }


}