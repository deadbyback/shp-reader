<?php



class ShpParser
{
    public const TYPE_NULL_SHAPE = 0;
    public const TYPE_POINT = 1;
    public const TYPE_POLYLINE = 3;
    public const TYPE_POLYGON = 5;
    public const TYPE_MULTIPOINT = 8;
    public const TYPE_POINT_Z = 11;
    public const TYPE_POLYLINE_Z = 13;
    public const TYPE_POLYGON_Z = 15;
    public const TYPE_MULTIPOINT_Z = 18;
    public const TYPE_POINT_M = 21;
    public const TYPE_POLYLINE_M = 23;
    public const TYPE_POLYGON_M = 25;
    public const TYPE_MULTIPOINT_M = 28;
    public const TYPE_MULTIPATCH = 31;

    private $shpFile;
    private $headerInfo = [];
    private $shpData = [];
    private $errorData = null;

    public function __construct(string $path)
    {
        $path .= '.shp';
        $this->shpFile = fopen($path, "rb");
        try {
            $this->loadHeaders();
            $this->loadRecords();
        } catch (\Exception $exception) {
            $this->errorData = $exception;
        } finally {
            fclose($this->shpFile);
        }
    }

    public function headerInfo()
    {
        return $this->headerInfo;
    }

    public function getShapeData()
    {
        return $this->shpData;
    }

    public function getErrorData()
    {
        return $this->errorData;
    }

    private function geomTypes()
    {
        return [
            0  => 'Null Shape',
            1  => 'Point',
            3  => 'PolyLine',
            5  => 'Polygon',
            8  => 'MultiPoint',
            11 => 'PointZ',
            13 => 'PolyLineZ',
            15 => 'PolygonZ',
            18 => 'MultiPointZ',
            21 => 'PointM',
            23 => 'PolyLineM',
            25 => 'PolygonM',
            28 => 'MultiPointM',
            31 => 'MultiPatch',
        ];
    }

    private function geoTypeFromID($id)
    {
        $geomTypes = $this->geomTypes();

        if (isset($geomTypes[$id])) {
            return $geomTypes[$id];
        }

        return null;
    }

    private function loadHeaders()
    {
        fseek($this->shpFile, 24, SEEK_SET);
        $length = $this->loadData("N");
        fseek($this->shpFile, 32, SEEK_SET);
        $shape_type = $this->geoTypeFromID($this->loadData("V"));

        $bounding_box = [];
        $bounding_box["xmin"] = $this->loadData("d");
        $bounding_box["ymin"] = $this->loadData("d");
        $bounding_box["xmax"] = $this->loadData("d");
        $bounding_box["ymax"] = $this->loadData("d");

        $this->headerInfo = [
            'length' => $length,
            'shapeType' => [
                'id' => $shape_type,
                'name' => $this->geoTypeFromID($shape_type),
            ],
            'boundingBox' => $bounding_box,
        ];
    }

    private function loadRecords()
    {
        fseek($this->shpFile, 100);

        while(!feof($this->shpFile)) {
            $record = $this->loadRecord();

            if(!empty($record['geom'])){
                $this->shpData[] = $record;
            }
        }
    }

    /**
     * Low-level data pull.
     */

    private function loadData($type)
    {
        $type_length = $this->loadDataLength($type);
        if ($type_length) {
            $fread_return = fread($this->shpFile, $type_length);
            if ($fread_return !== '') {
                $tmp = unpack($type, $fread_return);
                return current($tmp);
            }
        }

        return null;
    }

    private function loadDataLength($type)
    {
        $lengths = [
            'd' => 8,
            'V' => 4,
            'N' => 4,
        ];

        return $lengths[$type] ?? null;
    }

    // shpRecord functions.

    /**
     * @return array
     * @throws \Exception
     */
    private function loadRecord()
    {
        $recordNumber = $this->loadData("N");
        $this->loadData("N"); // unnecessary data.
        $shape_type = $this->loadData("V");

        $record = [
            'shapeType' => [
                'id' => $shape_type,
                'name' => $this->geoTypeFromID($shape_type),
            ],
        ];

        switch($record['shapeType']['name']){
            case 'Null Shape':
                $record['geom'] = $this->loadNullRecord();
                break;
            case 'Point':
                $record['geom'] = $this->loadPointRecord();
                break;
            case 'PolyLine':
                $record['geom'] = $this->loadPolyLineRecord();
                break;
            case 'Polygon':
                $record['geom'] = $this->loadPolygonRecord();
                break;
            case 'MultiPoint':
                $record['geom'] = $this->loadMultiPointRecord();
                break;
            default:
                throw new \Exception("The Shape Type {$record['shapeType']['name']} is not supported.");
                break;
        }

        return $record;
    }

    private function loadPoint()
    {
        $data = [];
        $data['x'] = $this->loadData("d");
        $data['y'] = $this->loadData("d");
        return $data;
    }

    private function loadNullRecord()
    {
        return [];
    }

    private function loadPolyLineRecord()
    {
        $return = [
            'bbox' => [
                'xmin' => $this->loadData("d"),
                'ymin' => $this->loadData("d"),
                'xmax' => $this->loadData("d"),
                'ymax' => $this->loadData("d"),
            ],
        ];

        $geometries = $this->processLineStrings();

        $return['numGeometries'] = $geometries['numParts'];
        if ($geometries['numParts'] > 1) {
            $return['wkt'] = 'MULTILINESTRING(' . implode(', ', $geometries['geometries']) . ')';
        }
        else {
            $return['wkt'] = 'LINESTRING(' . implode(', ', $geometries['geometries']) . ')';
        }

        return $return;
    }

    private function loadPolygonRecord()
    {
        $return = [
            'bbox' => [
                'xmin' => $this->loadData("d"),
                'ymin' => $this->loadData("d"),
                'xmax' => $this->loadData("d"),
                'ymax' => $this->loadData("d"),
            ],
        ];

        $geometries = $this->processLineStrings();

        $return['numGeometries'] = $geometries['numParts'];
        if ($geometries['numParts'] > 1) {
            $return['wkt'] = 'MULTIPOLYGON((' . implode('), (', $geometries['geometries']) . '))';
        }
        else {
            $return['wkt'] = 'POLYGON((' . implode(', ', $geometries['geometries']) . '))';
        }

        return $return;
    }

    /**
     * Process function for loadPolyLineRecord and loadPolygonRecord.
     * Returns geometries array.
     */

    private function processLineStrings()
    {
        $numParts = $this->loadData("V");
        $numPoints = $this->loadData("V");
        $geometries = [];

        $parts = [];
        for ($i = 0; $i < $numParts; $i++) {
            $parts[] = $this->loadData("V");
        }

        $parts[] = $numPoints;

        $points = [];
        for ($i = 0; $i < $numPoints; $i++) {
            $points[] = $this->loadPoint();
        }

        if ($numParts == 1) {
            for ($i = 0; $i < $numPoints; $i++) {
                $geometries[] = sprintf('%f %f', $points[$i]['x'], $points[$i]['y']);
            }

        }
        else {
            for ($i = 0; $i < $numParts; $i++) {
                $my_points = [];
                for ($j = $parts[$i]; $j < $parts[$i + 1]; $j++) {
                    $my_points[] = sprintf('%f %f', $points[$j]['x'], $points[$j]['y']);
                }
                $geometries[] = '(' . implode(', ', $my_points) . ')';
            }
        }

        return [
            'numParts' => $numParts,
            'geometries' => $geometries,
        ];
    }

    private function loadMultiPointRecord()
    {
        $return = [
            'bbox' => [
                'xmin' => $this->loadData("d"),
                'ymin' => $this->loadData("d"),
                'xmax' => $this->loadData("d"),
                'ymax' => $this->loadData("d"),
            ],
            'numGeometries' => $this->loadData("d"),
            'wkt' => '',
        ];

        $geometries = [];

        for ($i = 0; $i < $this->shpData['numGeometries']; $i++) {
            $point = $this->loadPoint();
            $geometries[] = sprintf('(%f %f)', $point['x'], $point['y']);
        }

        $return['wkt'] = 'MULTIPOINT(' . implode(', ', $geometries) . ')';
        return $return;
    }

    private function loadPointRecord()
    {
        $point = $this->loadPoint();

        return [
            'bbox' => [
                'xmin' => $point['x'],
                'ymin' => $point['y'],
                'xmax' => $point['x'],
                'ymax' => $point['y'],
            ],
            'numGeometries' => 1,
            'wkt' => sprintf('POINT(%f %f)', $point['x'], $point['y']),
        ];
    }
}