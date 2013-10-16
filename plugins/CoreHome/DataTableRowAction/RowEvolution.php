<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik_Plugins
 * @package CoreHome
 */
namespace Piwik\Plugins\CoreHome\DataTableRowAction;

use Exception;
use Piwik\API\Request;
use Piwik\API\ResponseBuilder;
use Piwik\Common;
use Piwik\Date;
use Piwik\Metrics;
use Piwik\Piwik;
use Piwik\Plugins\CoreVisualizations\Visualizations\JqplotGraph\Evolution as EvolutionViz;
use Piwik\Url;
use Piwik\ViewDataTable;

/**
 * ROW EVOLUTION
 * The class handles the popover that shows the evolution of a singe row in a data table
 * @package CoreHome
 */
class RowEvolution
{

    /** The current site id */
    protected $idSite;

    /** The api method to get the data. Format: Plugin.apiAction */
    protected $apiMethod;

    /** The label of the requested row */
    protected $label;

    /** The requested period */
    protected $period;

    /** The requested date */
    protected $date;

    /** The request segment */
    protected $segment;

    /** The metrics that are available for the requested report and period */
    protected $availableMetrics;

    /** The name of the dimension of the current report */
    protected $dimension;

    /**
     * The data
     * @var \Piwik\DataTable
     */
    protected $dataTable;

    /** The label of the current record */
    protected $rowLabel;

    /** The icon of the current record */
    protected $rowIcon;

    /** The type of graph that has been requested last */
    protected $graphType;

    /** The metrics for the graph that has been requested last */
    protected $graphMetrics;

    /** Whether or not to show all metrics in the evolution graph when to popover opens */
    protected $initiallyShowAllMetrics = false;

    /**
     * The constructor
     * Initialize some local variables from the request
     * @param int $idSite
     * @param Date $date ($this->date from controller)
     * @param null|string $graphType
     * @throws Exception
     */
    public function __construct($idSite, $date, $graphType = null)
    {
        $this->apiMethod = Common::getRequestVar('apiMethod', '', 'string');
        if (empty($this->apiMethod)) throw new Exception("Parameter apiMethod not set.");

        $this->label = ResponseBuilder::getLabelFromRequest($_GET);
        $this->label = $this->label[0];

        if ($this->label === '') throw new Exception("Parameter label not set.");

        $this->period = Common::getRequestVar('period', '', 'string');
        if (empty($this->period)) throw new Exception("Parameter period not set.");

        $this->idSite = $idSite;
        $this->graphType = $graphType;

        if ($this->period != 'range') {
            // handle day, week, month and year: display last X periods
            $end = $date->toString();
            list($this->date, $lastN) = EvolutionViz::getDateRangeAndLastN($this->period, $end);
        }
        $this->segment = \Piwik\API\Request::getRawSegmentFromRequest();

        $this->loadEvolutionReport();
    }

    /**
     * Render the popover
     * @param Piwik_CoreHome_Controller
     * @param View (the popover_rowevolution template)
     */
    public function renderPopover($controller, $view)
    {
        // render main evolution graph
        $this->graphType = 'graphEvolution';
        $this->graphMetrics = $this->availableMetrics;
        $view->graph = $controller->getRowEvolutionGraph($fetch = true, $rowEvolution = $this);

        // render metrics overview
        $view->metrics = $this->getMetricsToggles();

        // available metrics text
        $metricsText = Piwik::translate('RowEvolution_AvailableMetrics');
        $popoverTitle = '';
        if ($this->rowLabel) {
            $icon = $this->rowIcon ? '<img src="' . $this->rowIcon . '" alt="">' : '';
            $rowLabel = str_replace('/', '<wbr>/', str_replace('&', '<wbr>&', $this->rowLabel));
            $metricsText = sprintf(Piwik::translate('RowEvolution_MetricsFor'), $this->dimension . ': ' . $icon . ' ' . $rowLabel);
            $popoverTitle = $icon . ' ' . $rowLabel;
        }

        $view->availableMetricsText = $metricsText;
        $view->popoverTitle = $popoverTitle;

        return $view->render();
    }

    protected function loadEvolutionReport($column = false)
    {
        list($apiModule, $apiAction) = explode('.', $this->apiMethod);

        $parameters = array(
            'method'    => 'API.getRowEvolution',
            'label'     => $this->label,
            'apiModule' => $apiModule,
            'apiAction' => $apiAction,
            'idSite'    => $this->idSite,
            'period'    => $this->period,
            'date'      => $this->date,
            'format'    => 'original',
            'serialize' => '0'
        );
        if (!empty($this->segment)) {
            $parameters['segment'] = $this->segment;
        }

        if ($column !== false) {
            $parameters['column'] = $column;
        }

        $url = Url::getQueryStringFromParameters($parameters);

        $request = new Request($url);
        $report = $request->process();

        $this->extractEvolutionReport($report);
    }

    protected function extractEvolutionReport($report)
    {
        $this->dataTable = $report['reportData'];
        $this->rowLabel = Common::sanitizeInputValue($report['label']);
        $this->rowIcon = !empty($report['logo']) ? $report['logo'] : false;
        $this->availableMetrics = $report['metadata']['metrics'];
        $this->dimension = $report['metadata']['dimension'];
    }

    /**
     * Generic method to get an evolution graph or a sparkline for the row evolution popover.
     * Do as much as possible from outside the controller.
     * @param string|bool $graphType
     * @param array|bool $metrics
     * @return ViewDataTable
     */
    public function getRowEvolutionGraph($graphType = false, $metrics = false)
    {
        // set up the view data table
        $view = ViewDataTable::factory($graphType ? : $this->graphType, $this->apiMethod,
            $controllerAction = 'CoreHome.getRowEvolutionGraph', $forceDefault = true);
        $view->setDataTable($this->dataTable);

        if (!empty($this->graphMetrics)) { // In row Evolution popover, this is empty
            $view->config->columns_to_display = array_keys($metrics ? : $this->graphMetrics);
        }

        $view->config->show_goals = false;
        $view->config->show_all_views_icons = false;
        $view->config->show_active_view_icon = false;
        $view->config->show_related_reports = false;
        $view->config->show_series_picker = false;
        $view->config->show_footer_message = false;

        foreach ($this->availableMetrics as $metric => $metadata) {
            $view->config->translations[$metric] = $metadata['name'];
        }

        $view->config->external_series_toggle = 'RowEvolutionSeriesToggle';
        $view->config->external_series_toggle_show_all = $this->initiallyShowAllMetrics;

        return $view;
    }

    /**
     * Prepare metrics toggles with spark lines
     * @return array
     */
    protected function getMetricsToggles()
    {
        $i = 0;
        $metrics = array();
        foreach ($this->availableMetrics as $metric => $metricData) {
            $max = isset($metricData['max']) ? $metricData['max'] : 0;
            $min = isset($metricData['min']) ? $metricData['min'] : 0;
            $change = isset($metricData['change']) ? $metricData['change'] : false;

            $unit = Metrics::getUnit($metric, $this->idSite);
            $min .= $unit;
            $max .= $unit;

            $details = Piwik::translate('RowEvolution_MetricBetweenText', array($min, $max));

            if ($change !== false) {
                $lowerIsBetter = Metrics::isLowerValueBetter($metric);
                if (substr($change, 0, 1) == '+') {
                    $changeClass = $lowerIsBetter ? 'bad' : 'good';
                    $changeImage = $lowerIsBetter ? 'arrow_up_red' : 'arrow_up';
                } else if (substr($change, 0, 1) == '-') {
                    $changeClass = $lowerIsBetter ? 'good' : 'bad';
                    $changeImage = $lowerIsBetter ? 'arrow_down_green' : 'arrow_down';
                } else {
                    $changeClass = 'neutral';
                    $changeImage = false;
                }

                $change = '<span class="' . $changeClass . '">'
                    . ($changeImage ? '<img src="plugins/MultiSites/images/' . $changeImage . '.png" /> ' : '')
                    . $change . '</span>';

                $details .= ', ' . Piwik::translate('RowEvolution_MetricChangeText', $change);
            }

            $newMetric = array(
                'label'     => $metricData['name'],
                'details'   => $details,
                'sparkline' => $this->getSparkline($metric),
            );
            // Multi Rows, each metric can be for a particular row and display an icon
            if (!empty($metricData['logo'])) {
                $newMetric['logo'] = $metricData['logo'];
            }
            $metrics[] = $newMetric;
            $i++;
        }

        return $metrics;
    }

    /** Get the img tag for a sparkline showing a single metric */
    protected function getSparkline($metric)
    {
        // sparkline is always echoed, so we need to buffer the output
        $view = $this->getRowEvolutionGraph($graphType = 'sparkline', $metrics = array($metric => $metric));

        ob_start();
        $view->render();
        $spark = ob_get_contents();
        ob_end_clean();

        // undo header change by sparkline renderer
        header('Content-type: text/html');

        // base64 encode the image and put it in an img tag
        $spark = base64_encode($spark);
        return '<img src="data:image/png;base64,' . $spark . '" />';
    }

    /** Use the available metrics for the metrics of the last requested graph. */
    public function useAvailableMetrics()
    {
        $this->graphMetrics = $this->availableMetrics;
    }
}
