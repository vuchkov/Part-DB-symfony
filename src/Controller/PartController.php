<?php

declare(strict_types=1);

/**
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 * Copyright (C) 2019 Jan Böhmer (https://github.com/jbtronics)
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA
 */

namespace App\Controller;

use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Exceptions\AttachmentDownloadException;
use App\Form\Part\PartBaseType;
use App\Services\Attachments\AttachmentManager;
use App\Services\Attachments\AttachmentSubmitHandler;
use App\Services\Attachments\PartPreviewGenerator;
use App\Services\PricedetailHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @Route("/part")
 */
class PartController extends AbstractController
{
    /**
     * @Route("/{id}/info", name="part_info")
     * @Route("/{id}", requirements={"id"="\d+"})
     *
     * @param  Part  $part
     * @param  AttachmentManager  $attachmentHelper
     * @param  PricedetailHelper  $pricedetailHelper
     * @param  PartPreviewGenerator  $previewGenerator
     * @return Response
     */
    public function show(Part $part, AttachmentManager $attachmentHelper, PricedetailHelper $pricedetailHelper, PartPreviewGenerator $previewGenerator): Response
    {
        $this->denyAccessUnlessGranted('read', $part);

        return $this->render(
            'Parts/info/show_part_info.html.twig',
            [
                'part' => $part,
                'attachment_helper' => $attachmentHelper,
                'pricedetail_helper' => $pricedetailHelper,
                'pictures' => $previewGenerator->getPreviewAttachments($part),
            ]
        );
    }

    /**
     * @Route("/{id}/edit", name="part_edit")
     *
     * @param  Part  $part
     * @param  Request  $request
     * @param  EntityManagerInterface  $em
     * @param  TranslatorInterface  $translator
     * @param  AttachmentManager  $attachmentHelper
     * @param  AttachmentSubmitHandler  $attachmentSubmitHandler
     * @return Response
     */
    public function edit(Part $part, Request $request, EntityManagerInterface $em, TranslatorInterface $translator,
                         AttachmentManager $attachmentHelper, AttachmentSubmitHandler $attachmentSubmitHandler): Response
    {
        $this->denyAccessUnlessGranted('edit', $part);

        $form = $this->createForm(PartBaseType::class, $part);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            //Upload passed files
            $attachments = $form['attachments'];
            foreach ($attachments as $attachment) {
                /** @var FormInterface $attachment */
                $options = [
                    'secure_attachment' => $attachment['secureFile']->getData(),
                    'download_url' => $attachment['downloadURL']->getData(),
                ];

                try {
                    $attachmentSubmitHandler->handleFormSubmit($attachment->getData(), $attachment['file']->getData(), $options);
                } catch (AttachmentDownloadException $attachmentDownloadException) {
                    $this->addFlash(
                        'error',
                        $translator->trans('attachment.download_failed').' '.$attachmentDownloadException->getMessage()
                    );
                }
            }

            $em->persist($part);
            $em->flush();
            $this->addFlash('info', 'part.edited_flash');
            //Reload form, so the SIUnitType entries use the new part unit
            $form = $this->createForm(PartBaseType::class, $part);
        } elseif ($form->isSubmitted() && ! $form->isValid()) {
            $this->addFlash('error', 'part.edited_flash.invalid');
        }

        return $this->render('Parts/edit/edit_part_info.html.twig',
            [
                'part' => $part,
                'form' => $form->createView(),
                'attachment_helper' => $attachmentHelper,
            ]);
    }

    /**
     * @Route("/{id}/delete", name="part_delete", methods={"DELETE"})
     *
     * @param  Request  $request
     * @param  Part  $part
     * @return RedirectResponse
     */
    public function delete(Request $request, Part $part): RedirectResponse
    {
        $this->denyAccessUnlessGranted('delete', $part);

        if ($this->isCsrfTokenValid('delete'.$part->getId(), $request->request->get('_token'))) {
            $entityManager = $this->getDoctrine()->getManager();

            //Remove part
            $entityManager->remove($part);

            //Flush changes
            $entityManager->flush();

            $this->addFlash('success', 'part.deleted');
        }

        return $this->redirectToRoute('homepage');
    }

    /**
     * @Route("/new", name="part_new")
     *
     * @param  Request  $request
     * @param  EntityManagerInterface  $em
     * @param  TranslatorInterface  $translator
     * @param  AttachmentManager  $attachmentHelper
     * @param  AttachmentSubmitHandler  $attachmentSubmitHandler
     * @return Response
     */
    public function new(Request $request, EntityManagerInterface $em, TranslatorInterface $translator,
                        AttachmentManager $attachmentHelper, AttachmentSubmitHandler $attachmentSubmitHandler): Response
    {
        $new_part = new Part();

        $this->denyAccessUnlessGranted('create', $new_part);

        $cid = $request->get('cid', 1);

        $category = $em->find(Category::class, $cid);
        if (null !== $category) {
            $new_part->setCategory($category);
        }

        $form = $this->createForm(PartBaseType::class, $new_part);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            //Upload passed files
            $attachments = $form['attachments'];
            foreach ($attachments as $attachment) {
                /** @var FormInterface $attachment */
                $options = [
                    'secure_attachment' => $attachment['secureFile']->getData(),
                    'download_url' => $attachment['downloadURL']->getData(),
                ];

                try {
                    $attachmentSubmitHandler->handleFormSubmit($attachment->getData(), $attachment['file']->getData(), $options);
                } catch (AttachmentDownloadException $attachmentDownloadException) {
                    $this->addFlash(
                        'error',
                        $translator->trans('attachment.download_failed').' '.$attachmentDownloadException->getMessage()
                    );
                }
            }

            $em->persist($new_part);
            $em->flush();
            $this->addFlash('success', 'part.created_flash');

            return $this->redirectToRoute('part_edit', ['id' => $new_part->getID()]);
        }

        if ($form->isSubmitted() && ! $form->isValid()) {
            $this->addFlash('error', 'part.created_flash.invalid');
        }

        return $this->render('Parts/edit/new_part.html.twig',
            [
                'part' => $new_part,
                'form' => $form->createView(),
                'attachment_helper' => $attachmentHelper,
            ]);
    }

    /**
     * @Route("/{id}/clone", name="part_clone")
     *
     * @param  Part  $part
     * @param  Request  $request
     * @param  EntityManagerInterface  $em
     * @param  TranslatorInterface  $translator
     * @return RedirectResponse|Response
     */
    public function clone(Part $part, Request $request, EntityManagerInterface $em, TranslatorInterface $translator)
    {
        /** @var Part $new_part */
        $new_part = clone $part;

        $this->denyAccessUnlessGranted('create', $new_part);

        $form = $this->createForm(PartBaseType::class, $new_part);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($new_part);
            $em->flush();
            $this->addFlash('success', 'part.created_flash');

            return $this->redirectToRoute('part_edit', ['id' => $new_part->getID()]);
        }

        return $this->render('Parts/edit/new_part.html.twig',
            [
                'part' => $new_part,
                'form' => $form->createView(),
            ]);
    }
}
