<?php declare(strict_types=1);
/**
 * This file is part of the Magebit_Faq package
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magebit_Faq
 * to newer versions in the future.
 *
 * @copyright Copyright (c) 2025 Magebit (https://magebit.com/)
 * @author    Janis Engers <info@magebit.com>
 * @license   GNU General Public License ("GPL") v3.0
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Magebit\Faq\Controller\Adminhtml\Question;

use Magebit\Faq\Api\QuestionRepositoryInterface as QuestionRepository;
use Magebit\Faq\Model\QuestionFactory;
use Magento\Backend\App\Action;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\InputException;

/**
 * Save action.
 */
class Save extends Action
{
    /**
     * @param Action\Context $context
     * @param QuestionFactory $questionFactory
     * @param QuestionRepository $questionRepository
     */
    public function __construct(
        Action\Context $context,
        private QuestionFactory $questionFactory,
        private QuestionRepository $questionRepository
    ) {
        parent::__construct($context);
    }

    /**
     * Execute Action
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();
        $data = $this->getRequest()->getPostValue();

        if (!$data) {
            $resultRedirect->setPath('*/*/');
            return $resultRedirect->setPath('*/*/');
        }

        if (empty($data['id'])) {
            $data['id'] = null;
            $model = $this->questionFactory->create();
        } else {
            $model = $this->questionRepository->get((int)$data['id']);
        }

        $model->setData($data);

        try {
            $this->questionRepository->save($model);
        } catch (InputException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $resultRedirect->setPath('*/*/edit', ['id' => $model->getId()]);
        }

        $this->messageManager->addSuccessMessage(__('You saved the question.'));
        $redirect = $data['back'] ?? 'close';

        if ($redirect ==='continue') {
            return $resultRedirect->setPath('*/*/edit', ['id' => $model->getId()]);
        }

        return $resultRedirect->setPath('*/*/');
    }
}
