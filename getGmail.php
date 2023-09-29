<?php

function conectarBaseDeDatos()
{
    $conexion = mysqli_connect('localhost', 'root', '', 'xxhr');
    if (!$conexion) {
        die('Error al conectar a la base de datos: ' . mysqli_error($conexion));
    }
    return $conexion;
}

function procesarCorreo($inbox, $conexion, $email_number)
{
    $structure = imap_fetchstructure($inbox, $email_number);

    if (isset($structure->parts)) {
        for ($i = 0; $i < count($structure->parts); $i++) {
            if ($structure->parts[$i]->ifdparameters) {
                foreach ($structure->parts[$i]->dparameters as $object) {
                    if (strtolower($object->attribute) == 'filename') {
                        if (strtolower(pathinfo($object->value, PATHINFO_EXTENSION)) == 'xml') {
                            $xml_content = imap_fetchbody($inbox, $email_number, $i + 1);
                            $xml_content = base64_decode($xml_content);

                            // Mostrar el contenido del XML en la pantalla
                            echo htmlspecialchars($xml_content);

                            // Convertir a UTF-8
                            $xml_content = utf8_encode($xml_content);
                            $xml = simplexml_load_string($xml_content);

                            $contadorRegistrosNulos = 0;
                            if ($xml !== false && isset($xml->Empleados->Empleado)) {

                                foreach ($xml->Empleados->Empleado as $empleado) {
                                    $empNum = isset($empleado['EMP_NUM']) ? trim($empleado['EMP_NUM']) : '';

                                    // Verificar EMP_NUM y realizar las operaciones correspondientes
                                    if (!empty($empNum)) {
                                        $query = "SELECT EMP_NUM, ESTATUS FROM empleados WHERE EMP_NUM = '{$empNum}'";
                                        $resultado = mysqli_query($conexion, $query);

                                        if (!$resultado) {
                                            die('Error al ejecutar la consulta: ' . mysqli_error($conexion));
                                        }

                                        if (mysqli_num_rows($resultado) > 0) {
                                            // El registro ya existe en la base de datos
                                            $row = mysqli_fetch_assoc($resultado);
                                            $existingStatus = $row['ESTATUS'];

                                            if ($existingStatus == 'A') {
                                                // EMP_NUM existe y su ESTATUS es 'A', buscar el registro en la tabla de empleados
                                                $empNum = $empleado['EMP_NUM'];
                                                $query = "SELECT * FROM empleados WHERE EMP_NUM = '$empNum'";
                                                $resultado = mysqli_query($conexion, $query);

                                                if (!$resultado) {
                                                    die('Error al ejecutar la consulta: ' . mysqli_error($conexion));
                                                }

                                                if (mysqli_num_rows($resultado) > 0) {
                                                    // El registro ya existe en la base de datos, obtener los datos actuales
                                                    $fila = mysqli_fetch_assoc($resultado);
                                                    $diferentesCampos = [];

                                                    foreach ($empleado->attributes() as $campo => $valor) {
                                                        // Comparar el valor del campo actual en el XML con el valor en la base de datos
                                                        if ($campo !== 'EMP_NUM' && $campo !== 'ESTATUS' && $fila[$campo] !== (string)$valor) {
                                                            $diferentesCampos[] = $campo;
                                                        }
                                                    }

                                                    if (!empty($diferentesCampos)) {
                                                        // Llamar a la función para actualizar el empleado y registrar en historicos
                                                        actualizarEmpleado($conexion, $empNum, $empleado);
                                                        // No es necesario actualizar los campos aquí, ya que se hacen en la función
                                                    }
                                                }
                                            } elseif ($existingStatus == 'I') {
                                                // EMP_NUM existe y su ESTATUS es 'I', cambiar a 'A'
                                                cambiarEstatusA($conexion, $empleado);
                                                // Comparar campos y actualizar la información si es necesario
                                            }
                                        } else {
                                            // El registro no existe, insertar el registro
                                            insertarRegistro($conexion, $empleado);
                                        }
                                    } else {
                                        $contadorRegistrosNulos++;
                                    }
                                }
                            } else {
                                echo "No se pudo cargar el XML o no contiene la estructura esperada.";
                            }
                        }
                    }
                }
            }
        }
    }
}

function cambiarEstatusA($conexion, $empleado)
{
    $empNum = $empleado['EMP_NUM'];

    // Cambia el estatus de 'I' a 'A' en la tabla de empleados
    $query = "UPDATE empleados SET ESTATUS = 'A' WHERE EMP_NUM = '$empNum'";
    $resultado = mysqli_query($conexion, $query);

    if (!$resultado) {
        die('Error al cambiar el estatus: ' . mysqli_error($conexion));
    }

    // Obtén los datos actuales de la tabla empleados
    $query = "SELECT * FROM empleados WHERE EMP_NUM = '$empNum'";
    $resultado = mysqli_query($conexion, $query);

    if (!$resultado) {
        die('Error al obtener los datos actuales: ' . mysqli_error($conexion));
    }

    if (mysqli_num_rows($resultado) > 0) {
        $fila = mysqli_fetch_assoc($resultado);

        // Actualiza los campos en la tabla de empleados con los valores del XML
        foreach ($empleado->attributes() as $campo => $valor) {
            if ($campo !== 'EMP_NUM' && $fila[$campo] !== (string)$valor) {
                $query = "UPDATE empleados SET $campo = '" . (string)$valor . "' WHERE EMP_NUM = '$empNum'";
                $resultado = mysqli_query($conexion, $query);

                if (!$resultado) {
                    die("Error al actualizar el campo $campo: " . mysqli_error($conexion));
                }
            }
        }

        // Ahora que los datos antiguos se han actualizado, guárdalos en la tabla de empleados_historicos
        insertarRegistroHistorico($conexion, $empNum, $fila);
    }
}

function insertarRegistroHistorico($conexion, $empNum, $registroAntiguo)
{
    // Obtener la fecha actual
    $fecha = date('Y-m-d');

    // Crear la consulta para insertar el registro histórico con ESTATUS 'I'
    $query = "INSERT INTO empleados_historicos (FECHA, EMP_NUM, ESTATUS";

    // Preparar los valores del registro antiguo para la inserción
    $values = "VALUES ('$fecha', '$empNum', 'I'";

    foreach ($registroAntiguo as $campo => $valor) {
        // Excluir el campo EMP_NUM y ESTATUS en la inserción de campos y valores
        if ($campo !== 'EMP_NUM' && $campo !== 'ESTATUS') {
            // Agregar el campo a la consulta y su valor correspondiente
            $query .= ", $campo";
            $values .= ", '$valor'";
        }
    }
    $query .= ") $values)";
    // Ejecutar la consulta
    $resultado = mysqli_query($conexion, $query);
    if (!$resultado) {
        die('Error al insertar en empleados_historicos: ' . mysqli_error($conexion));
    }
}
function buscarEmpleadosEnBD($conexion)
{
    $empleadosEnBD = [];
    $query = "SELECT EMP_NUM, ESTATUS FROM empleados";
    $resultado = mysqli_query($conexion, $query);

    if (!$resultado) {
        die('Error al ejecutar la consulta: ' . mysqli_error($conexion));
    }
    while ($row = mysqli_fetch_assoc($resultado)) {
        $empleadosEnBD[$row['EMP_NUM']] = $row['ESTATUS'];
    }
    return $empleadosEnBD;
}
function insertarRegistro($conexion, $empleado)
{
    $query = "INSERT INTO empleados (EMP_NUM, EMP_NAME, EMP_RFC, DIRE, DEPT, ORGANIZACION, POS_NAME, 
            POS_STATUS, JOB_NAME, NOM_ID_1, MARITAL_ESTATUS, POS_TIPO_DESC, POS_REF, 
            CATE_FED_ORIGINAL, CAT_FED, SDO_FED, GPO_FED, HRS_FED, EMP_CURP, 
            EMP_IMSS, EMP_SEX, EMP_AGE, EMP_BIRTHDATE, EMP_PRI_CON, EMP_ACT_CON, 
            ASG_INI, ASG_FIN, ASG_NUM, ASG_SIN, SINDICALIZADO_N_S, TIPO_CONTRATO, 
            ASG_SDO, ASG_SDO_FEC, ASG_HOR, NOM_NAME_1, ASG_REF, EMAIL, QUINQUENIO, PASSWORD, ESTATUS
        )
        VALUES (
            '{$empleado['EMP_NUM']}', '{$empleado['EMP_NAME']}', '{$empleado['EMP_RFC']}', '{$empleado['DIRE']}', '{$empleado['DEPT']}', '{$empleado['ORGANIZACION']}', '{$empleado['POS_NAME']}', 
            '{$empleado['POS_STATUS']}', '{$empleado['JOB_NAME']}', '{$empleado['NOM_ID_1']}', '{$empleado['MARITAL_ESTATUS']}', '{$empleado['POS_TIPO_DESC']}', '{$empleado['POS_REF']}', 
            '{$empleado['CATE_FED_ORIGINAL']}', '{$empleado['CAT_FED']}', '{$empleado['SDO_FED']}', '{$empleado['GPO_FED']}', '{$empleado['HRS_FED']}', '{$empleado['EMP_CURP']}', 
            '{$empleado['EMP_IMSS']}', '{$empleado['EMP_SEX']}', '{$empleado['EMP_AGE']}', '{$empleado['EMP_BIRTHDATE']}', '{$empleado['EMP_PRI_CON']}', '{$empleado['EMP_ACT_CON']}', 
            '{$empleado['ASG_INI']}', '{$empleado['ASG_FIN']}', '{$empleado['ASG_NUM']}', '{$empleado['ASG_SIN']}', '{$empleado['SINDICALIZADO_N_S']}', '{$empleado['TIPO_CONTRATO']}', 
            '{$empleado['ASG_SDO']}', '{$empleado['ASG_SDO_FEC']}', '{$empleado['ASG_HOR']}', '{$empleado['NOM_NAME_1']}', '{$empleado['ASG_REF']}', '{$empleado['EMAIL']}', '{$empleado['QUINQUENIO']}', '0', 'A'
        )";

    $resultado = mysqli_query($conexion, $query);

    if (!$resultado) {
        die('Error al ejecutar la consulta: ' . mysqli_error($conexion));
    }
}

function actualizarEmpleado($conexion, $empNum, $empleado)
{
    // Obtener la fecha actual
    $fecha = date('Y-m-d');
    // Crear un array para almacenar los campos que son diferentes
    $camposDiferentes = [];
    // Obtener los datos actuales del empleado
    $query = "SELECT * FROM empleados WHERE EMP_NUM = '$empNum'";
    $resultado = mysqli_query($conexion, $query);
    if (!$resultado) {
        die('Error al ejecutar la consulta: ' . mysqli_error($conexion));
    }
    if (mysqli_num_rows($resultado) > 0) {
        $fila = mysqli_fetch_assoc($resultado);
        // Comparar los campos del empleado actual con los del XML
        foreach ($empleado->attributes() as $campo => $valor) {
            if ($campo !== 'EMP_NUM' && $campo !== 'ESTATUS') {
                $valorXML = (string)$valor;
                $valorBD = $fila[$campo];
                // Verificar si el campo es numérico
                if (is_numeric($valorXML) && is_numeric($valorBD)) {
                    // Redondear los valores antes de la comparación (ajusta la precisión)
                    $valorXML = round($valorXML, 2); // 2 decimales, ajusta según sea necesario
                    $valorBD = round($valorBD, 2); // 2 decimales, ajusta según sea necesario
                }
                if ($valorXML !== $valorBD) {
                    $camposDiferentes[$campo] = $valorXML;
                }
            }
        }
        // Si hay campos diferentes, actualizar la tabla de empleados
        if (!empty($camposDiferentes)) {
            // Actualizar los campos en la tabla de empleados con los valores del XML
            foreach ($camposDiferentes as $campo => $valor) {
                $query = "UPDATE empleados SET $campo = '" . mysqli_real_escape_string($conexion, $valor) . "' WHERE EMP_NUM = '$empNum'";
                $resultado = mysqli_query($conexion, $query);

                if (!$resultado) {
                    die("Error al actualizar el campo $campo: " . mysqli_error($conexion));
                }
            }
            // Insertar en empleados_historicos solo si hay cambios significativos
            $query = "INSERT INTO empleados_historicos (FECHA, EMP_NUM";
            foreach ($camposDiferentes as $campo => $valor) {
                $query .= ", $campo";
            }
            $query .= ") VALUES ('$fecha', '$empNum'";
            foreach ($camposDiferentes as $campo => $valor) {
                $query .= ", '" . mysqli_real_escape_string($conexion, $fila[$campo]) . "'";
            }
            $query .= ")";
            $resultado = mysqli_query($conexion, $query);
            if (!$resultado) {
                die('Error al insertar en empleados_historicos: ' . mysqli_error($conexion));
            }
        }
    }
}


// Función para verificar si un empleado con EMP_NUM está en el XML
function existeEmpleadoEnXML($inbox, $email_number, $empNum)
{
    $structure = imap_fetchstructure($inbox, $email_number);

    if (isset($structure->parts)) {
        for ($i = 0; $i < count($structure->parts); $i++) {
            if ($structure->parts[$i]->ifdparameters) {
                foreach ($structure->parts[$i]->dparameters as $object) {
                    if (strtolower($object->attribute) == 'filename') {
                        if (strtolower(pathinfo($object->value, PATHINFO_EXTENSION)) == 'xml') {
                            $xml_content = imap_fetchbody($inbox, $email_number, $i + 1);
                            $xml_content = base64_decode($xml_content);

                            $xml = simplexml_load_string($xml_content);

                            if ($xml !== false && isset($xml->Empleados->Empleado)) {
                                foreach ($xml->Empleados->Empleado as $empleado) {
                                    $xml_empNum = isset($empleado['EMP_NUM']) ? trim($empleado['EMP_NUM']) : '';

                                    if ($xml_empNum == $empNum) {
                                        return true; // El empleado con EMP_NUM está en el XML
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    return false; // El empleado con EMP_NUM no está en el XML
}

// Función para cambiar el estatus de 'A' a 'I' en la tabla de empleados
function cambiarEstatusI($conexion, $empNum)
{
    $query = "UPDATE empleados SET ESTATUS = 'I' WHERE EMP_NUM = '$empNum'";
    $resultado = mysqli_query($conexion, $query);

    if (!$resultado) {
        die('Error al cambiar el estatus: ' . mysqli_error($conexion));
    }
}

$hostname = '{imap.gmail.com:993/imap/ssl}INBOX';
$username = 'aquasw.uteq7@gmail.com';
$password = 'tidiadamkgetuizn';
$inbox = imap_open($hostname, $username, $password) or die('No se pudo conectar: ' . imap_last_error());

date_default_timezone_set('America/Mexico_City');
$fechaActual = date("d-m-Y");
$asuntoBusqueda = 'SUBJECT "XXHR_UTEQ_ESTRUCTURA_DINAMICA-' . $fechaActual . '"';
$emails = imap_search($inbox, $asuntoBusqueda);

if ($emails) {
    $conexion = conectarBaseDeDatos();
    $empleadosEnBD = buscarEmpleadosEnBD($conexion);

    foreach ($emails as $email_number) {
        procesarCorreo($inbox, $conexion, $email_number);
    }

    // Antes de cerrar la conexión, verifica si hay empleados en la base de datos que no están en el XML
    foreach ($empleadosEnBD as $empNum => $estatus) {
        if ($estatus == 'A' && !existeEmpleadoEnXML($inbox, $email_number, $empNum)) {
            cambiarEstatusI($conexion, $empNum);
        }
    }

    mysqli_close($conexion);
}

imap_close($inbox);

?>
