<?php
/**
 * FlipDev_AttributeManager
 *
 * @category  FlipDev
 * @package   FlipDev_AttributeManager
 * @author    Philipp Breitsprecher <philippbreitsprecher@gmail.com>
 * @copyright Copyright (c) 2024-2025 FlipDev
 */

declare(strict_types=1);

namespace FlipDev\AttributeManager\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\UrlInterface;

/**
 * Approval Actions Column
 */
class ApprovalActions extends Column
{
    /**
     * @var UrlInterface
     */
    private UrlInterface $urlBuilder;

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $urlBuilder
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Prepare Data Source
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as &$item) {
                $name = $this->getData('name');
                $item[$name]['view'] = [
                    'href' => $this->urlBuilder->getUrl(
                        'flipdev_attributes/approval/view',
                        ['id' => $item['proposal_id']]
                    ),
                    'label' => __('View'),
                ];

                if ($item['status'] === 'pending') {
                    $item[$name]['approve'] = [
                        'href' => $this->urlBuilder->getUrl(
                            'flipdev_attributes/approval/approve',
                            ['id' => $item['proposal_id']]
                        ),
                        'label' => __('Approve'),
                        'confirm' => [
                            'title' => __('Approve Proposal'),
                            'message' => __('Are you sure you want to approve this proposal?'),
                        ],
                    ];

                    $item[$name]['reject'] = [
                        'href' => $this->urlBuilder->getUrl(
                            'flipdev_attributes/approval/reject',
                            ['id' => $item['proposal_id']]
                        ),
                        'label' => __('Reject'),
                        'confirm' => [
                            'title' => __('Reject Proposal'),
                            'message' => __('Are you sure you want to reject this proposal?'),
                        ],
                    ];
                }

                if ($item['status'] === 'approved') {
                    $item[$name]['execute'] = [
                        'href' => $this->urlBuilder->getUrl(
                            'flipdev_attributes/approval/execute',
                            ['id' => $item['proposal_id']]
                        ),
                        'label' => __('Execute'),
                        'confirm' => [
                            'title' => __('Execute Proposal'),
                            'message' => __('Are you sure you want to execute this proposal?'),
                        ],
                    ];
                }

                $item[$name]['delete'] = [
                    'href' => $this->urlBuilder->getUrl(
                        'flipdev_attributes/approval/delete',
                        ['id' => $item['proposal_id']]
                    ),
                    'label' => __('Delete'),
                    'confirm' => [
                        'title' => __('Delete Proposal'),
                        'message' => __('Are you sure you want to delete this proposal?'),
                    ],
                ];
            }
        }

        return $dataSource;
    }
}
