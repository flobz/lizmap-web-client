<?php
/**
 * @author    3liz
 * @copyright 2020 3liz
 *
 * @see      http://3liz.com
 *
 * @license Mozilla Public License : http://www.mozilla.org/MPL/
 */
class qgisExpressionUtils
{
    /**
     * Returns criteria dependencies (fields) for a QGIS expression.
     *
     * @param string $exp A QGIS expression string
     *
     * @return array The list of criteria dependencies
     */
    public static function getCriteriaFromExpression($exp)
    {
        if ($exp === null || trim($exp) === '') {
            return array();
        }
        preg_match_all('/"([^"]+)"/', $exp, $matches);
        if (count($matches) < 2) {
            return array();
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * Returns criteria dependencies (fields) for QGIS expressions.
     *
     * @param array $expressions list of QGIS expressions
     *
     * @return array The list of criteria dependencies
     */
    public static function getCriteriaFromExpressions($expressions)
    {
        $criteriaFrom = array();
        foreach ($expressions as $id => $exp) {
            if ($exp === null || trim($exp) === '') {
                continue;
            }
            $criteriaFrom = array_merge($criteriaFrom, self::getCriteriaFromExpression($exp));
        }

        return array_values(array_unique($criteriaFrom));
    }

    /**
     * Returns criteria dependencies (fields) in current_value() for QGIS
     * expression.
     *
     * @param string $exp A QGIS expression string
     *
     * @return array The list of criteria dependencies
     */
    public static function getCurrentValueCriteriaFromExpression($exp)
    {
        preg_match_all('/\\bcurrent_value\\(\\s*\'([^)]*)\'\\s*\\)/', $exp, $matches);
        if (count($matches) == 2) {
            return array_values(array_unique($matches[1]));
        }

        return array();
    }

    /**
     * Returns true if @current_geometry is in the QGIS expression.
     *
     * @param string $exp A QGIS expression string
     *
     * @return bool @current_geometry is in the QGIS expression
     */
    public static function hasCurrentGeometry($exp)
    {
        return preg_match('/\\B@current_geometry\\b/', $exp) === 1;
    }

    public static function updateExpressionByUser($layer, $expression)
    {
        $project = $layer->getProject();
        $repository = $project->getRepository();
        // No filter data by login rights
        if (jAcl2::check('lizmap.tools.loginFilteredLayers.override', $repository->getKey())) {
            return $expression;
        }

        // get login filters
        $loginFilters = $project->getLoginFilters(array($layer->getName()));

        // login filters array is empty
        if (empty($loginFilters)) {
            return $expression;
        }

        // layer not in login filters array
        if (array_key_exists($layer->getName(), $loginFilters)) {
            return $expression;
        }

        return '('.$expression.') AND ('.$loginFilters[$layer->getName()].')';
    }

    public static function evaluateExpressions($layer, $expressions, $form_feature = null)
    {
        // Update expressions with filter by user
        $updatedExp = array();
        foreach ($expressions as $k => $exp) {
            $updatedExp[$k] = self::updateExpressionByUser($layer, $exp);
        }
        // Evaluate the expression by qgis
        $project = $layer->getProject();
        $plugins = $project->getQgisServerPlugins();
        if (array_key_exists('Lizmap', $plugins)) {
            $params = array(
                'service' => 'EXPRESSION',
                'request' => 'Evaluate',
                'map' => $project->getRelativeQgisPath(),
                'layer' => $layer->getName(),
                'expressions' => json_encode($updatedExp),
            );
            if ($form_feature) {
                $params['feature'] = json_encode($form_feature);
                $params['form_scope'] = 'true';
            }

            // Request evaluate expression
            $json = self::request($params);
            if (!$json) {
                return null;
            }
            if (property_exists($json, 'status') && $json->status != 'success') {
                // TODO parse errors
                // if (property_exists($json, 'errors')) {
                // }
                jLog::log($data, 'error');
            } elseif (property_exists($json, 'results') &&
                array_key_exists(0, $json->results)) {
                // Get results
                return $json->results[0];
            } else {
                // Data not well formed
                jLog::log($data, 'error');
            }
        }

        return null;
    }

    public static function getFeatureWithFormScope($layer, $expression, $form_feature, $fields)
    {
        $project = $layer->getProject();
        $plugins = $project->getQgisServerPlugins();
        if (array_key_exists('Lizmap', $plugins)) {
            // build parameters
            $params = array(
                'service' => 'EXPRESSION',
                'request' => 'getFeatureWithFormScope',
                'map' => $project->getRelativeQgisPath(),
                'layer' => $layer->getName(),
                'filter' => self::updateExpressionByUser($layer, $expression),
                'form_feature' => json_encode($form_feature),
                'fields' => implode(',', $fields),
            );

            // Request getFeatureWithFormsScope
            $json = self::request($params);
            if (!$json || !property_exists($json, 'features')) {
                return array();
            }

            return $json->features;
        }

        return array();
    }

    /**
     * Return form group visibilities.
     *
     * @param qgisAttributeEditorElement $attributeEditorForm
     * @param jFormsBase                 $form
     *
     * @return array visibilities, an associated array with group html id as key and boolean as value
     */
    public static function evaluateGroupVisibilities($attributeEditorForm, $form)
    {
        // qgisForm::getAttributesEditorForm can return null
        if ($attributeEditorForm === null || $form === null) {
            return array();
        }
        // Get criterias and expressions to evaluate
        // and prepare visibilities
        $criteriaFrom = array();
        $expressions = array();
        $visibilities = array();
        $visibilityExpressions = $attributeEditorForm->getGroupVisibilityExpressions();
        foreach ($visibilityExpressions as $id => $exp) {
            $visibilities[$id] = true;

            if ($exp === null || trim($exp) === '') {
                // Expression is empty
                continue;
            }

            $crit = self::getCriteriaFromExpression($exp);
            if (count($crit) === 0) {
                // No criteria dependencies found
                continue;
            }

            $expressions[$id] = $exp;
            $criteriaFrom = array_merge($criteriaFrom, $crit);
        }
        $criteriaFrom = array_values(array_unique($criteriaFrom));

        // No expressions to evaluate or no criteria dependencies.
        if (count($expressions) === 0 || count($criteriaFrom) === 0) {
            return $visibilities;
        }

        // build feature's form
        $geom = null;
        $values = array();
        foreach ($criteriaFrom as $ref) {
            if ($ref == $form->getData('liz_geometryColumn')) {
                // from wkt to geom
                $wkt = trim($form->getData($ref));
                $geom = lizmapWkt::parse($wkt);
            } else {
                // properties
                $values[$ref] = $form->getData($ref);
            }
        }

        $privateData = $form->getContainer()->privateData;
        $repository = $privateData['liz_repository'];
        $project = $privateData['liz_project'];
        $lproj = lizmap::getProject($repository.'~'.$project);

        $layer = $lproj->getLayer($privateData['liz_layerId']);

        // Update expressions with filter by user
        $updatedExp = array();
        foreach ($expressions as $k => $exp) {
            $updatedExp[$k] = self::updateExpressionByUser($layer, $exp);
        }

        $form_feature = array(
            'type' => 'Feature',
            'geometry' => $geom,
            'properties' => $values,
        );

        $params = array(
            'service' => 'EXPRESSION',
            'request' => 'Evaluate',
            'map' => $lproj->getRelativeQgisPath(),
            'layer' => $layer->getName(),
            'expressions' => json_encode($expressions),
            'feature' => json_encode($form_feature),
            'form_scope' => 'true',
        );

        // Request evaluate constraint expressions
        $url = lizmapProxy::constructUrl($params, array('method' => 'post'));
        list($data, $mime, $code) = lizmapProxy::getRemoteData($url);

        // Check data from request
        if (strpos($mime, 'text/json') === 0 || strpos($mime, 'application/json') === 0) {
            $json = json_decode($data);
            if (property_exists($json, 'status') && $json->status != 'success') {
                // TODO parse errors
                // if (property_exists($json, 'errors')) {
                // }
                jLog::log($data, 'error');
            } elseif (property_exists($json, 'results') &&
                array_key_exists(0, $json->results)) {
                // Get results
                $results = (array) $json->results[0];
                foreach ($results as $id => $result) {
                    if ($result === 0) {
                        $visibilities[$id] = false;
                    }
                }
            } else {
                // Data not well formed
                jLog::log($data, 'error');
            }
        }

        return $visibilities;
    }

    protected static function request($params)
    {
        $url = lizmapProxy::constructUrl($params, array('method' => 'post'));
        list($data, $mime, $code) = lizmapProxy::getRemoteData($url);

        // Check data from request
        if (strpos($mime, 'text/json') === 0 || strpos($mime, 'application/json') === 0) {
            return json_decode($data);
        }

        return null;
    }
}
